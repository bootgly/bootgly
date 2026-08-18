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
 * Drives the native RESP queue driver against a scripted stub Redis and prints one JSON
 * line describing both what the client received and what the server actually executed —
 * the two must agree, or a job left the ready set without reaching a worker (QUEUE-1).
 *
 * It runs in its own PHP process because the driver prefers ext-redis whenever that
 * extension is loaded, which makes the RESP path unreachable in-process. The spec starts
 * it with the ini scan directory cleared so the extension is absent.
 *
 * Nothing here is a test; the assertions live in `4.2-redis-desync.Test.php`.
 */

// ! Its own process, so it boots the framework itself
require __DIR__ . '/../../../../autoboot.php';

use Bootgly\ACI\Queues;
use Bootgly\ACI\Queues\Job;


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
      $value = $call();

      return [
         'job'   => $value instanceof Job,
         'value' => $value instanceof Job ? $value->Handler : $value,
         'throw' => null,
      ];
   }
   catch (Throwable $Throwable) {
      return ['job' => false, 'value' => null, 'throw' => $Throwable::class];
   }
};

// ! The single job the stub server holds in its ready set
$Payload = serialize(new Job('send-invoice', ['id' => 42]));

/**
 * Fork a stub server that answers by command VERB, so one script stays valid whatever
 * order the client ends up asking in, and record every verb it really executed.
 *
 * @param array<string,callable> $answers verb => fn (int $seen): string
 */
$scenario = function (array $answers, callable $drive, float $delay = 0.0): array {
   $capture = sys_get_temp_dir() . '/bootgly-queue-desync-' . getmypid() . '.txt';
   @unlink($capture);

   $Listener = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

   if ($Listener === false) {
      return ['served' => '', 'error' => "bind failed: {$error}"];
   }

   $address = (string) stream_socket_get_name($Listener, false);
   $port = (int) substr($address, strrpos($address, ':') + 1);

   $PID = pcntl_fork();

   if ($PID === 0) {
      $served = [];
      $counts = [];
      $first = true;

      while (true) {
         // ! Verb-driven, so there is no step count to stop on: the scenario ends when
         //   the client stops coming back. Localhost reconnects are immediate.
         $Peer = @stream_socket_accept($Listener, 1.0);

         if ($Peer === false) {
            break;
         }

         stream_set_timeout($Peer, 5);

         while (true) {
            $command = @fread($Peer, 16384);

            if ($command === false || $command === '') {
               break;
            }
            if (preg_match('/^\*\d+\r\n\$\d+\r\n([A-Z]+)\r\n/', $command, $matches) !== 1) {
               break 2;
            }

            $verb = $matches[1];
            $counts[$verb] = ($counts[$verb] ?? 0) + 1;
            $served[] = $verb;
            file_put_contents($capture, implode(',', $served));

            // ! One slow answer is all it takes
            if ($first === true && $delay > 0.0) {
               usleep((int) ($delay * 1000000));
            }
            $first = false;

            $answer = $answers[$verb] ?? null;
            @fwrite($Peer, $answer === null ? "+OK\r\n" : $answer($counts[$verb]));
         }

         @fclose($Peer);
      }

      @fclose($Listener);
      exit(0);
   }

   @fclose($Listener);

   $result = $drive($port);

   // ! The child ends on its own once its accept times out — ext-posix is absent in this
   //   lane, so there is no signal to send it
   pcntl_waitpid($PID, $status);

   $result['served'] = (string) (@file_get_contents($capture) ?: '');
   @unlink($capture);

   return $result;
};

/**
 * Fork a stub server that replies from $script, one step per command received, so a
 * scenario can hand out malformed or surplus frames the verb-driven server above cannot
 * express. Steps are consumed in order across connections, so a reconnect continues where
 * the last one stopped.
 *
 * @param array<int,callable> $script
 */
$scripted = function (array $script, callable $drive): array {
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

   pcntl_waitpid($PID, $status);

   return $result;
};

$config = function (int $port, float $timeout): array {
   return [
      'driver'  => 'redis',
      'host'    => '127.0.0.1',
      'port'    => $port,
      'prefix'  => 'q:',
      'timeout' => $timeout,
   ];
};

// ! One job in the ready set: the first ZRANGEBYSCORE offers it, later ones are empty
$answers = [
   'ZCARD'         => fn (int $seen): string => ":1\r\n",
   'ZRANGEBYSCORE' => fn (int $seen): string => $seen === 1
      ? "*1\r\n$" . strlen($Payload) . "\r\n{$Payload}\r\n"
      : "*0\r\n",
   'ZREM'          => fn (int $seen): string => ":1\r\n",
   'ZADD'          => fn (int $seen): string => ":1\r\n",
];

// @ One slow first reply with a sub-second configured timeout: the abandoned reply used
//   to become the next command's answer, so reserve() removed a job it never delivered
$loss = $scenario(
   $answers,
   function (int $port) use ($config, $probe): array {
      $Queue = new Queues($config($port, 0.5))->fetch('jobs');

      $first = $probe(fn () => $Queue->reserve());

      // ! Let the late reply land in the socket buffer
      usleep(250000);

      return [
         'first'  => $first,
         'second' => $probe(fn () => $Queue->reserve()),
      ];
   },
   0.1
);

// @ Ordinary traffic must be untouched by the hardening
$control = $scenario(
   $answers,
   function (int $port) use ($config, $probe): array {
      $Queue = new Queues($config($port, 5.0))->fetch('jobs');

      $enqueued = $probe(fn () => $Queue->enqueue(new Job('send-invoice', ['id' => 42])));
      $counted = $probe(fn () => $Queue->count());
      $Reserved = $Queue->reserve();

      return [
         'enqueued'  => $enqueued,
         'counted'   => $counted,
         'reserved'  => ['job' => $Reserved instanceof Job, 'value' => null, 'throw' => null],
         'completed' => $Reserved instanceof Job
            ? $probe(fn () => $Queue->complete($Reserved))
            : ['job' => false, 'value' => null, 'throw' => 'nothing reserved'],
      ];
   }
);

// @ A reply that stops mid-frame: the connection must go, not carry the fragment into
//   the next command (this is the path that exercises drop() and the read() catch)
$stall = $scripted(
   [
      function ($Peer): void {
         @fwrite($Peer, ":1");
         usleep(1200000);
      },
      function ($Peer): void {
         @fwrite($Peer, ":7\r\n");
      },
   ],
   function (int $port) use ($config, $probe): array {
      $Queue = new Queues($config($port, 1.0))->fetch('jobs');

      return [
         'stalled' => $probe(fn () => $Queue->count()),
         'next'    => $probe(fn () => $Queue->count()),
      ];
   }
);

// @ Two replies for one command: the stream is already offset, so say so
$surplus = $scripted(
   [
      function ($Peer): void {
         @fwrite($Peer, ":3\r\n:99\r\n");
      },
      function ($Peer): void {
         @fwrite($Peer, ":7\r\n");
      },
   ],
   function (int $port) use ($config, $probe): array {
      $Queue = new Queues($config($port, 1.0))->fetch('jobs');

      return [
         'surplus' => $probe(fn () => $Queue->count()),
         'after'   => $probe(fn () => $Queue->count()),
      ];
   }
);

$emit([
   'loss'    => $loss,
   'stall'   => $stall,
   'surplus' => $surplus,
   'control' => $control,
]);
