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
 * Drives the native RESP cache driver over TLS against a stub Redis and prints one
 * JSON line with the outcome of a TLS connect and of a plain-TCP control. It runs in its
 * own PHP process because the driver prefers ext-redis whenever that extension is loaded,
 * which makes the native lane — the one CACHE-13 lives in — unreachable in-process. The
 * spec starts it with the ini scan directory cleared and `openssl.cafile` pointed at the
 * certificate it minted, because connect() passes no stream context of its own.
 *
 * Nothing here is a test; the assertions live in `5.8-redis-tls-connect.Test.php`.
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
if (extension_loaded('openssl') === false) {
   $emit(['skip' => 'ext-openssl is unavailable']);
}
// ? Only ext-sockets arms the Nagle tuning block the entry lives in
if (extension_loaded('sockets') === false) {
   $emit(['skip' => 'ext-sockets is unavailable']);
}

$certificate = (string) ($_SERVER['argv'][2] ?? '');
$key = (string) ($_SERVER['argv'][3] ?? '');

if (is_file($certificate) === false || is_file($key) === false) {
   $emit(['skip' => 'certificate files were not handed over']);
}

/**
 * Fork a stub Redis — TLS or plain TCP — that answers `+OK` to every command (echoing
 * back an `ECHO` argument, which is what resync() waits for) and records every command it
 * heard, tagged with the connection it arrived on. Returns the scenario result with the
 * stub's transcript attached.
 */
$serve = function (bool $secure, callable $drive) use ($certificate, $key): array {
   $Context = stream_context_create([
      'ssl' => ['local_cert' => $certificate, 'local_pk' => $key]
   ]);
   $scheme = $secure === true ? 'tls' : 'tcp';
   $Listener = @stream_socket_server(
      "{$scheme}://127.0.0.1:0",
      $errno,
      $error,
      STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
      $Context
   );

   if ($Listener === false) {
      return ['error' => "bind failed: {$error}"];
   }

   $address = (string) stream_socket_get_name($Listener, false);
   $port = (int) substr($address, strrpos($address, ':') + 1);
   $transcript = (string) tempnam(sys_get_temp_dir(), 'bootgly-tls-');
   // ! A sentinel the parent touches once its scenario is over, so the stub leaves
   //   its accept window early instead of spinning it out to the deadline
   $stop = "{$transcript}.stop";

   $PID = pcntl_fork();

   if ($PID === 0) {
      $connections = 0;
      $deadline = microtime(true) + 8.0;

      while (microtime(true) < $deadline && is_file($stop) === false) {
         $Peer = @stream_socket_accept($Listener, 0.25);

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
      // ! The stub checks the sentinel between accepts; reaping and unlinking sit in a
      //   `finally` because a scenario that throws would otherwise leave both behind
      @touch($stop);
      pcntl_waitpid($PID, $status);

      $lines = array_values(array_filter(
         explode("\n", (string) @file_get_contents($transcript))
      ));

      @unlink($transcript);
      @unlink($stop);
   }

   $result['transcript'] = $lines;

   return $result;
};
$attempt = function (callable $work): array {
   try {
      return ['outcome' => 'served', 'value' => $work()];
   }
   catch (Throwable $Throwable) {
      return [
         'outcome' => 'threw',
         'class' => $Throwable::class,
         'message' => $Throwable->getMessage()
      ];
   }
};
$build = function (int $port, bool $secure): Redis {
   return new Redis(new Config([
      'driver' => 'redis',
      'host' => '127.0.0.1',
      'port' => $port,
      'secure' => $secure,
      'timeout' => 2.0,
      'prefix' => $secure === true ? 'tls' : 'tcp',
   ]));
};

// # The entry's trigger: secure => true on the native lane with ext-sockets loaded
$TLS = $serve(true, fn (int $port): array => $attempt(
   fn (): mixed => $build($port, true)->store('probe', 'value')
));

// # Control that must not move: the plain-TCP lane
$TCP = $serve(false, fn (int $port): array => $attempt(
   fn (): mixed => $build($port, false)->store('probe', 'value')
));

$emit(['tls' => $TLS, 'tcp' => $TCP]);
