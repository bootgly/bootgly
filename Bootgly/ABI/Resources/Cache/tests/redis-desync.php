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
 * Drives the native RESP cache driver against a scripted stub Redis and prints one JSON
 * line describing what came back. It runs in its own PHP process because the driver
 * prefers ext-redis whenever that extension is loaded, which makes the RESP path — the
 * one CACHE-8 lives in — unreachable in-process. The spec starts it with the ini scan
 * directory cleared so the extension is absent.
 *
 * Nothing here is a test; the assertions live in `5.6-redis-desync.Test.php`.
 */

// ! Its own process, so it boots the framework itself
require __DIR__ . '/../../../../../autoboot.php';

use Bootgly\ABI\Resources\Cache;


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

$probe = function (callable $call): array {
   try {
      return ['value' => $call(), 'throw' => null];
   }
   catch (Throwable $Throwable) {
      return ['value' => null, 'throw' => $Throwable::class];
   }
};

// ! Mirrors Drivers\Redis::pack(), so the stub answers bytes the driver can unpack
$bulk = function (mixed $value): string {
   $raw = is_int($value) ? (string) $value : "\x01" . serialize($value);

   return '$' . strlen($raw) . "\r\n" . $raw . "\r\n";
};

/**
 * Fork a stub server that replies from $script, one step per command received. Steps are
 * consumed in order across connections, so a reconnect continues where the last one
 * stopped.
 *
 * @param array<int,callable> $script
 */
$scenario = function (array $script, callable $drive): array {
   $Listener = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

   if ($Listener === false) {
      return ['error' => "bind failed: {$error}"];
   }

   $address = (string) stream_socket_get_name($Listener, false);
   $port = (int) substr($address, strrpos($address, ':') + 1);

   $PID = pcntl_fork();

   if ($PID === 0) {
      $step = 0;

      while ($step < count($script)) {
         $Peer = @stream_socket_accept($Listener, 3.0);

         if ($Peer === false) {
            break;
         }

         stream_set_timeout($Peer, 5);

         while ($step < count($script)) {
            $command = @fread($Peer, 16384);

            if ($command === false || $command === '') {
               break;
            }

            $script[$step]($Peer);
            $step++;
         }

         @fclose($Peer);
      }

      @fclose($Listener);
      exit(0);
   }

   @fclose($Listener);

   $result = $drive($port);

   // ! The child ends on its own once the script runs out (or its accept times out) —
   //   ext-posix is absent in this lane, so there is no signal to send it
   pcntl_waitpid($PID, $status);

   return $result;
};

$config = function (int $port, float $timeout, array $extra = []): array {
   return [
      'driver'  => 'redis',
      'host'    => '127.0.0.1',
      'port'    => $port,
      'prefix'  => '',
      'timeout' => $timeout,
   ] + $extra;
};

// @ An honest server that answers 100 ms late, with a sub-second configured timeout
$leak = $scenario(
   [
      function ($Peer) use ($bulk): void {
         usleep(100000);
         @fwrite($Peer, $bulk('ALICE-TOKEN'));
      },
      function ($Peer) use ($bulk): void {
         usleep(100000);
         @fwrite($Peer, $bulk('BOB-TOKEN'));
      },
   ],
   function (int $port) use ($config, $probe): array {
      $Cache = new Cache($config($port, 0.5));

      $alice = $probe(fn () => $Cache->fetch('user:1:secret'));

      // ! Let the late reply land in the socket buffer, which is what a slow Redis
      //   means for the command that comes next
      usleep(250000);

      return [
         'alice' => $alice,
         'bob'   => $probe(fn () => $Cache->fetch('user:2:secret')),
      ];
   }
);

// @ A reply that stops mid-frame, with a timeout the int cast cannot collapse
$stall = $scenario(
   [
      function ($Peer): void {
         @fwrite($Peer, "\$20\r\n\x01s:11:\"ALI");
         usleep(1200000);
      },
      function ($Peer) use ($bulk): void {
         @fwrite($Peer, $bulk('BOB-TOKEN'));
      },
   ],
   function (int $port) use ($config, $probe): array {
      $Cache = new Cache($config($port, 1.0));

      return [
         'stalled' => $probe(fn () => $Cache->fetch('user:1:secret')),
         'next'    => $probe(fn () => $Cache->fetch('user:2:secret')),
      ];
   }
);

// @ Two replies for one command
$surplus = $scenario(
   [
      function ($Peer) use ($bulk): void {
         @fwrite($Peer, $bulk('FIRST') . $bulk('SURPLUS'));
      },
      function ($Peer) use ($bulk): void {
         @fwrite($Peer, $bulk('AFTER'));
      },
   ],
   function (int $port) use ($config, $probe): array {
      $Cache = new Cache($config($port, 1.0));

      return [
         'surplus' => $probe(fn () => $Cache->fetch('a')),
         'after'   => $probe(fn () => $Cache->fetch('b')),
      ];
   }
);

// @ Ordinary operations must be untouched by the hardening
$control = $scenario(
   [
      function ($Peer): void { @fwrite($Peer, "+OK\r\n"); },
      function ($Peer) use ($bulk): void { @fwrite($Peer, $bulk('v')); },
      function ($Peer): void { @fwrite($Peer, ":5\r\n"); },
      function ($Peer): void { @fwrite($Peer, ":1\r\n"); },
   ],
   function (int $port) use ($config, $probe): array {
      $Cache = new Cache($config($port, 5.0));

      return [
         'store'     => $probe(fn () => $Cache->store('k', 'v')),
         'fetch'     => $probe(fn () => $Cache->fetch('k')),
         'increment' => $probe(fn () => $Cache->increment('c', 5)),
         'delete'    => $probe(fn () => $Cache->delete('k')),
      ];
   }
);

// @ A rejected AUTH: the reply is an error frame, which the Decoder returns as a value
$refused = $scenario(
   [
      function ($Peer): void {
         @fwrite($Peer, "-WRONGPASS invalid username-password pair\r\n");
      },
      function ($Peer): void {
         @fwrite($Peer, "-NOAUTH Authentication required.\r\n");
      },
   ],
   function (int $port) use ($config, $probe): array {
      $Cache = new Cache($config($port, 2.0, ['password' => 'wrong']));

      return [
         'store' => $probe(fn () => $Cache->store('k', 'v')),
         'fetch' => $probe(fn () => $Cache->fetch('k')),
      ];
   }
);

// @ A rejected SELECT would otherwise leave the driver working on database 0
$database = $scenario(
   [
      function ($Peer): void {
         @fwrite($Peer, "-ERR DB index is out of range\r\n");
      },
      function ($Peer): void {
         @fwrite($Peer, "+OK\r\n");
      },
   ],
   function (int $port) use ($config, $probe): array {
      $Cache = new Cache($config($port, 2.0, ['database' => 9]));

      return ['store' => $probe(fn () => $Cache->store('k', 'v'))];
   }
);

// @ An error reply to an ordinary command is a COMPLETE frame, so the connection stays
//   aligned — it must be reported without being dropped
$errored = $scenario(
   [
      function ($Peer): void {
         @fwrite($Peer, "-WRONGTYPE Operation against a key holding the wrong kind of value\r\n");
      },
      function ($Peer) use ($bulk): void {
         @fwrite($Peer, $bulk('AFTER'));
      },
   ],
   function (int $port) use ($config, $probe): array {
      $Cache = new Cache($config($port, 2.0));

      return [
         'errored' => $probe(fn () => $Cache->fetch('list-key')),
         'after'   => $probe(fn () => $Cache->fetch('ok-key')),
      ];
   }
);

// @ A healthy handshake must still pass through untouched
$handshake = $scenario(
   [
      function ($Peer): void { @fwrite($Peer, "+OK\r\n"); },
      function ($Peer): void { @fwrite($Peer, "+OK\r\n"); },
      function ($Peer) use ($bulk): void { @fwrite($Peer, $bulk('v')); },
   ],
   function (int $port) use ($config, $probe): array {
      $Cache = new Cache($config($port, 5.0, ['password' => 'right', 'database' => 3]));

      return ['fetch' => $probe(fn () => $Cache->fetch('k'))];
   }
);

$emit([
   'leak'      => $leak,
   'stall'     => $stall,
   'surplus'   => $surplus,
   'control'   => $control,
   'refused'   => $refused,
   'database'  => $database,
   'errored'   => $errored,
   'handshake' => $handshake,
]);
