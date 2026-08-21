<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

/*
 * Drives the native RESP cache driver against a stub Redis and prints one JSON line
 * describing which streams it opened and what the stub heard on them. It runs in its own
 * PHP process because the driver prefers ext-redis whenever that extension is loaded,
 * which makes the RESP path — the one CACHE-11 lives in — unreachable in-process. The
 * spec starts it with the ini scan directory cleared so the extension is absent.
 *
 * Nothing here is a test; the assertions live in `5.7-redis-persistent-identity.Test.php`.
 */

// ! Its own process, so it boots the framework itself
require __DIR__ . '/../../../../../autoboot.php';

use Bootgly\ABI\Resources\Cache\Config;
use Bootgly\ABI\Resources\Cache\Drivers\Redis;


$emit = function (array $payload): never {
   echo json_encode($payload), "\n";
   exit(0);
};

// ? The RESP path is dead code while ext-redis is loaded
if (extension_loaded('redis') === true) {
   $emit(['skip' => 'ext-redis is loaded, the native RESP path is unreachable']);
}
if (function_exists('pcntl_fork') === false) {
   $emit(['skip' => 'pcntl_fork() is unavailable']);
}

/**
 * Fork a stub Redis that answers `+OK` to everything (echoing back an `ECHO` argument,
 * which is what the driver's resync() waits for) and records every command it heard,
 * tagged with the connection it arrived on. Returns the transcript file.
 */
$serve = function (callable $drive): array {
   $Listener = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

   if ($Listener === false) {
      return ['error' => "bind failed: {$error}"];
   }

   $address = (string) stream_socket_get_name($Listener, false);
   $port = (int) substr($address, strrpos($address, ':') + 1);
   $transcript = (string) tempnam(sys_get_temp_dir(), 'bootgly-persistent-');

   $PID = pcntl_fork();

   if ($PID === 0) {
      $connections = 0;
      $deadline = microtime(true) + 6.0;

      while (microtime(true) < $deadline) {
         $Peer = @stream_socket_accept($Listener, 0.5);

         if ($Peer === false) {
            continue;
         }

         $connections++;
         stream_set_timeout($Peer, 1);

         while (microtime(true) < $deadline) {
            $bytes = @fread($Peer, 16384);

            if ($bytes === false || $bytes === '') {
               break;
            }

            // @@ One reply per command in whatever arrived
            $offset = 0;
            $size = strlen($bytes);

            while ($offset < $size && $bytes[$offset] === '*') {
               $crlf = strpos($bytes, "\r\n", $offset);

               if ($crlf === false) {
                  break;
               }

               $count = (int) substr($bytes, $offset + 1, $crlf - $offset - 1);
               $offset = $crlf + 2;
               $arguments = [];

               for ($i = 0; $i < $count; $i++) {
                  $crlf = strpos($bytes, "\r\n", $offset);

                  if ($crlf === false) {
                     break 2;
                  }

                  $length = (int) substr($bytes, $offset + 1, $crlf - $offset - 1);
                  $arguments[] = substr($bytes, $crlf + 2, $length);
                  $offset = $crlf + 2 + $length + 2;
               }

               @file_put_contents(
                  $transcript,
                  $connections . '|' . implode(' ', $arguments) . "\n",
                  FILE_APPEND
               );

               // ? A command the stub refuses, so a section can leave an error frame
               //   behind for whoever is handed this stream next
               if (strtoupper((string) ($arguments[0] ?? '')) === 'FAILME') {
                  @fwrite($Peer, "-ERR stub refused\r\n");

                  continue;
               }

               if (strtoupper((string) ($arguments[0] ?? '')) === 'ECHO') {
                  $token = (string) ($arguments[1] ?? '');

                  @fwrite($Peer, '$' . strlen($token) . "\r\n{$token}\r\n");

                  continue;
               }

               @fwrite($Peer, "+OK\r\n");
            }
         }

         @fclose($Peer);
      }

      @fclose($Listener);
      exit(0);
   }

   @fclose($Listener);

   $lines = [];

   try {
      $result = $drive($port);
   }
   finally {
      // ! The child ends on its own once its accept window closes — ext-posix is absent in
      //   this lane, so there is no signal to send it. Reaping and unlinking sit in a
      //   `finally` because a scenario that throws would otherwise leave both behind.
      pcntl_waitpid($PID, $status);

      $lines = array_values(array_filter(
         explode("\n", (string) @file_get_contents($transcript))
      ));

      @unlink($transcript);
   }

   $result['transcript'] = $lines;

   return $result;
};
$build = function (
   int $port,
   int $database,
   bool $persistent,
   string $password = '',
): Redis {
   return new Redis(new Config([
      'driver' => 'redis',
      'host' => '127.0.0.1',
      'port' => $port,
      'database' => $database,
      'persistent' => $persistent,
      'password' => $password,
      'timeout' => 2.0,
      'prefix' => 'persistent:',
   ]));
};
$stream = function (Redis $Driver): string {
   return (string) new ReflectionProperty($Driver, 'Socket')->getValue($Driver);
};

// # Two drivers, two databases, one endpoint
//   PHP pools a persistent stream by target alone, so both are handed the same socket
//   unless the one that needs its own session stays out of the pool.
$shared = $serve(function (int $port) use ($build, $stream): array {
   $Default = $build($port, 0, true);
   $Default->store('a', 'A', 60);

   $Other = $build($port, 3, true);
   $Other->store('b', 'B', 60);

   return [
      'default.stream' => $stream($Default),
      'other.stream' => $stream($Other),
      'shared' => $stream($Default) === $stream($Other),
   ];
});

// # A stream a previous owner left pointed elsewhere
//   The pooled connect must state its own database rather than trust what it inherits.
$borrowed = $serve(function (int $port) use ($build, $stream): array {
   $Stranger = @stream_socket_client(
      "tcp://127.0.0.1:{$port}",
      $errno,
      $error,
      2.0,
      STREAM_CLIENT_CONNECT | STREAM_CLIENT_PERSISTENT
   );

   if (is_resource($Stranger) === false) {
      return ['error' => "stranger connect failed: {$error}"];
   }

   @fwrite($Stranger, "*2\r\n\$6\r\nSELECT\r\n\$1\r\n3\r\n");
   usleep(100000);
   @fread($Stranger, 256);

   $Default = $build($port, 0, true);
   $Default->store('c', 'C', 60);

   return [
      'stranger.stream' => (string) $Stranger,
      'default.stream' => $stream($Default),
      'borrowed' => $stream($Default) === (string) $Stranger,
   ];
});

// # …and a stream whose previous owner never read its reply
//   Realigning is what resync() is for, and it only works while nothing else has read
//   a reply yet: read() rethrows a stranger's error frame and raises desync on a
//   surplus one, so any command ordered ahead of the landmark fails the caller with
//   somebody else's error — on a stream that was perfectly recoverable.
$undrained = $serve(function (int $port) use ($build, $stream): array {
   $Stranger = @stream_socket_client(
      "tcp://127.0.0.1:{$port}",
      $errno,
      $error,
      2.0,
      STREAM_CLIENT_CONNECT | STREAM_CLIENT_PERSISTENT
   );

   if (is_resource($Stranger) === false) {
      return ['error' => "stranger connect failed: {$error}"];
   }

   // ! Written and deliberately never read — the debris stays on the wire
   @fwrite($Stranger, "*1\r\n\$6\r\nFAILME\r\n");
   usleep(100000);

   $Default = $build($port, 0, true);
   $borrowed = false;

   try {
      $Default->store('d', 'D', 60);
      $borrowed = $stream($Default) === (string) $Stranger;
      $outcome = 'served';
   }
   catch (Throwable $Throwable) {
      $outcome = 'threw: ' . $Throwable->getMessage();
   }

   return [
      'outcome' => $outcome,
      'borrowed' => $borrowed,
   ];
});

// # Two identities on one endpoint keep their own connections
//   `AUTH` is connection state exactly as `SELECT` is, and PHP's pool key carries neither.
//   On a shared stream the second driver's `AUTH` re-authenticates the socket the first one
//   is still holding, so the first one's later commands run as somebody else.
$identities = $serve(function (int $port) use ($build, $stream): array {
   $Alpha = $build($port, 0, true, 'alpha');
   $Alpha->store('e', 'E', 60);

   $Beta = $build($port, 0, true, 'beta');
   $Beta->store('f', 'F', 60);

   return [
      'alpha.stream' => $stream($Alpha),
      'beta.stream' => $stream($Beta),
      'shared' => $stream($Alpha) === $stream($Beta),
   ];
});

$emit([
   'shared' => $shared,
   'borrowed' => $borrowed,
   'undrained' => $undrained,
   'identities' => $identities,
]);
