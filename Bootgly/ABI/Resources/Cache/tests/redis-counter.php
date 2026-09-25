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
 * Drives the Redis cache driver's counters and prints one JSON line with what came back.
 * It runs in its own PHP process so the spec can drive BOTH transports: the driver prefers
 * ext-redis whenever that extension is loaded, and the spec starts a second run with the
 * ini scan directory cleared so the native RESP path is the one exercised.
 *
 * argv: [host, port] of a live Redis for the live legs; the stub legs need none.
 *
 * Nothing here is a test; the assertions live in `5.9-redis-counter-expiry.Test.php`.
 */

// ! Its own process, so it boots the framework itself
require __DIR__ . '/../../../../../autoboot.php';

use Bootgly\ABI\Data\RESP\Decoder;
use Bootgly\ABI\Resources\Cache;


$emit = function (array $payload): never {
   echo json_encode($payload), "\n";
   exit(0);
};

$lane = extension_loaded('redis') === true ? 'ext' : 'native';
if (function_exists('pcntl_fork') === false) {
   $emit(['lane' => $lane, 'skip' => 'pcntl_fork() is unavailable']);
}

$host = (string) ($_SERVER['argv'][2] ?? '');
$port = (int) ($_SERVER['argv'][3] ?? 0);

$config = static fn (int $port, string $prefix, string $host = '127.0.0.1'): array => [
   'driver'  => 'redis',
   'host'    => $host,
   'port'    => $port,
   'prefix'  => $prefix,
   'timeout' => 1.0,
];

/**
 * Fork a stub server that answers $reply to the first command and records every frame the
 * connection carries until the client leaves (or 300 ms pass).
 *
 * @return array{0:int,1:int,2:string} [port, child PID, log file]
 */
$stub = static function (string $reply): array {
   $log = (string) tempnam(sys_get_temp_dir(), 'bootgly-counter-');
   $Listener = stream_socket_server('tcp://127.0.0.1:0');
   $name = (string) stream_socket_get_name($Listener, false);
   $port = (int) substr($name, strrpos($name, ':') + 1);
   $PID = pcntl_fork();
   if ($PID === 0) {
      $Peer = @stream_socket_accept($Listener, 3.0);
      if ($Peer !== false) {
         stream_set_timeout($Peer, 3);
         $stream = (string) @fread($Peer, 65536);
         @fwrite($Peer, $reply);
         // ! Whatever the client sends after the reply is part of the call too
         stream_set_timeout($Peer, 0, 300_000);
         while (($more = @fread($Peer, 65536)) !== false && $more !== '') {
            $stream .= $more;
         }
         $frames = [];
         $Decoder = new Decoder;
         foreach ((array) $Decoder->decode($stream) as $Frame) {
            $frames[] = $Frame;
         }
         file_put_contents($log, (string) json_encode($frames));
         @fclose($Peer);
      }
      exit(0);
   }
   fclose($Listener);

   return [$port, $PID, $log];
};

/**
 * Fork a relay to a live Redis that forwards the first reply, then cuts both sides —
 * a connection lost right after the counter's first command answered.
 *
 * @return array{0:int,1:int} [port, child PID]
 */
$relay = static function (string $host, int $upstream): array {
   $Listener = stream_socket_server('tcp://127.0.0.1:0');
   $name = (string) stream_socket_get_name($Listener, false);
   $port = (int) substr($name, strrpos($name, ':') + 1);
   $PID = pcntl_fork();
   if ($PID === 0) {
      $Client = @stream_socket_accept($Listener, 3.0);
      $Up = @stream_socket_client("tcp://{$host}:{$upstream}", $errno, $error, 1.0);
      while ($Client !== false && $Up !== false) {
         $read = [$Client, $Up];
         $write = null;
         $except = null;
         if (@stream_select($read, $write, $except, 3) < 1) {
            break;
         }
         foreach ($read as $Stream) {
            $data = @fread($Stream, 65536);
            if ($data === false || $data === '') {
               break 2;
            }
            if ($Stream === $Client) {
               fwrite($Up, $data);
            }
            else {
               fwrite($Client, $data);
               break 2;
            }
         }
      }
      exit(0);
   }
   fclose($Listener);

   return [$port, $PID];
};

$legs = [];

// @ S) A counter with a TTL is one command — the whole call — and its script creates the
//    counter with its expiry (SET … EX … NX) before counting, and answers GET
[$stubbed, $PID, $log] = $stub("\$1\r\n1\r\n");
$Counter = new Cache($config($stubbed, ''));
try {
   $Counter->increment('quota', 1, 60);
}
catch (Throwable) {
   // ? The frames sent are what this leg reads
}
unset($Counter);
pcntl_waitpid($PID, $status);
$frames = json_decode((string) file_get_contents($log), true);
@unlink($log);
$script = is_array($frames) ? (string) ($frames[0][1] ?? '') : '';
$set = strpos($script, "redis.call('SET', KEYS[1], '0', 'EX', ARGV[2], 'NX')");
$count = strpos($script, "redis.call('INCRBY', KEYS[1], ARGV[1])");
$legs['S'] = is_array($frames) && count($frames) === 1
   && strtoupper((string) ($frames[0][0] ?? '')) === 'EVAL'
   && array_slice($frames[0], 2) === ['1', 'quota', '1', '60']
   && $set !== false && $count !== false && $set < $count
   && str_contains($script, "return redis.call('GET', KEYS[1])")
   && str_contains($script, 'EXPIRE') === false;

// @ E) An error reply is raised — never answered as a count of 0
[$stubbed, $PID, $log] = $stub("-ERR value is not an integer or out of range\r\n");
try {
   (new Cache($config($stubbed, '')))->increment('quota', 1, 60);
   $legs['E'] = false;
}
catch (Throwable) {
   $legs['E'] = true;
}
pcntl_waitpid($PID, $status);
@unlink($log);

// ? The live legs need a reachable Redis
$Probe = $port > 0 ? @fsockopen($host, $port, $errno, $error, 0.2) : false;
if (is_resource($Probe) === false) {
   $emit(['lane' => $lane, 'legs' => $legs, 'live' => false]);
}
fclose($Probe);

$prefix = "bootgly-test-counter-{$lane}-" . bin2hex(random_bytes(4)) . ':';
$Cache = new Cache($config($port, $prefix, $host));

// @ A) A connection cut right after the counter's command answered leaves the expiry set
[$relayed, $PID] = $relay($host, $port);
try {
   (new Cache($config($relayed, $prefix)))->increment('a', 1, 60);
}
catch (Throwable) {
   // ? What the key holds afterwards is what this leg reads
}
pcntl_waitpid($PID, $status);
$remain = $Cache->remain('a');
$legs['A'] = $remain > 0 && $remain <= 60;

// @ O) An expiry the server refuses leaves no count behind
try {
   $Cache->increment('o', 1, 9_300_000_000_000_000);
   $thrown = false;
}
catch (Throwable) {
   $thrown = true;
}
$legs['O'] = $thrown && $Cache->remain('o') === -2;

// @ R) A live window is never re-armed — not after a decrement back to 0, not by +0
$Cache->increment('w', 1, 100);
$Cache->renew('w', 50);
$Cache->decrement('w', 1, 100);
$Cache->increment('w', 1, 100);
$Cache->increment('z', 0, 100);
$Cache->renew('z', 50);
$Cache->increment('z', 0, 100);
$window = $Cache->remain('w');
$zero = $Cache->remain('z');
$legs['R'] = $window > 0 && $window <= 50 && $zero > 0 && $zero <= 50;

// @ P) The count comes back exact beyond 2^53
try {
   $legs['P'] = $Cache->increment('big', 2 ** 53 + 1, 100) === 2 ** 53 + 1
      && $Cache->increment('big', 1, 100) === 2 ** 53 + 2
      && $Cache->increment('max', PHP_INT_MAX - 1, 100) === PHP_INT_MAX - 1;
}
catch (Throwable) {
   $legs['P'] = false;
}

// @ Z) A TTL of 0 or less keeps a plain counter without expiry
try {
   $legs['Z'] = $Cache->increment('z0', 1, 0) === 1
      && $Cache->increment('zn', 1, -5) === 1
      && $Cache->remain('z0') === -1
      && $Cache->remain('zn') === -1;
}
catch (Throwable) {
   $legs['Z'] = false;
}

// @ N) A value that is not a counter raises, with and without a TTL
$Cache->store('text', 'not a number', 100);
$raised = 0;
foreach ([100, 0] as $TTL) {
   try {
      $Cache->increment('text', 1, $TTL);
   }
   catch (Throwable) {
      $raised++;
   }
}
$legs['N'] = $raised === 2;

// @ C) Control: a counter stored without expiry keeps none (the TTL arms creation only)
$Cache->store('perm', 5, 0);
$Cache->increment('perm', 1, 100);
$legs['C'] = $Cache->remain('perm') === -1;

$Cache->clear();

$emit(['lane' => $lane, 'legs' => $legs, 'live' => true]);
