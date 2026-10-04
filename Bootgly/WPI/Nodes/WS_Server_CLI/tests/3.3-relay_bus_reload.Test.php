<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ACI\Logs\Data\Display;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Nodes\WS_Server_CLI;
use Bootgly\WPI\Nodes\WS_Server_CLI\Configs;


// ! Embedded fixture: reload() replays this exact file through pcntl_exec.
if (
   realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)
   && ($_SERVER['argv'][1] ?? null) === '--ws-bus-reload-probe'
) {
   $root = rtrim((string) ($_SERVER['argv'][2] ?? ''), '/');
   $port = (int) ($_SERVER['argv'][3] ?? 0);
   if (realpath("{$root}/autoboot.php") === false || $port < 1024 || $port > 65535) {
      exit(2);
   }
   // ? First launch only — the replayed image already leads its session
   @posix_setsid();

   $_SERVER['SCRIPT_FILENAME'] = '';
   require "{$root}/autoboot.php";
   Display::show(Display::NONE);

   // ! A spec-only class: its state inodes are this spec's alone to remove
   final class BusReloadProbe extends WS_Server_CLI
   {
   }

   $Server = new BusReloadProbe(Modes::Foreground);
   $Server->configure(new Configs(host: '127.0.0.1', port: $port, workers: 4, heartbeatInterval: 0));
   $Server->start();
   exit(0);
}


/**
 * A hot reload hands the listeners to a fresh image and closes everything
 * else the old master holds: the cross-worker bus is rebuilt by the fresh
 * image, so neither the master nor the workers it forks accumulate sockets
 * across reloads, and every reloaded server still answers the handshake.
 */
return new Test(
   description: 'WS-6: hot reloads never accumulate the cross-worker bus sockets',
   skip: function_exists('posix_kill') === false
      || function_exists('posix_setsid') === false
      || function_exists('proc_open') === false
      || is_dir('/proc/self/fd') === false,
   test: new Assertions(Case: function (): Generator {
      // @ Reserve a free loopback port.
      $Reservation = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
      $name = is_resource($Reservation) ? (string) stream_socket_get_name($Reservation, false) : '';
      $port = (int) substr($name, (int) strrpos($name, ':') + 1);
      if (is_resource($Reservation)) {
         fclose($Reservation);
      }

      /** The socket descriptors $PID holds. */
      $Sockets = static function (int $PID): int {
         $count = 0;
         foreach ((array) @scandir("/proc/{$PID}/fd") as $fd) {
            if (str_starts_with((string) @readlink("/proc/{$PID}/fd/{$fd}"), 'socket:')) {
               $count++;
            }
         }

         return $count;
      };
      /** The worker PIDs of $master, sorted. */
      $Workers = static function (int $master): array {
         $PIDs = array_map('intval', preg_split('/\s+/', trim((string) @file_get_contents(
            "/proc/{$master}/task/{$master}/children"
         ))) ?: []);
         $PIDs = array_values(array_filter($PIDs, static fn (int $PID): bool => $PID > 0));
         sort($PIDs);

         return $PIDs;
      };
      /** Whether the server answers a WebSocket handshake with 101. */
      $Handshake = static function () use ($port): bool {
         $Client = @stream_socket_client("tcp://127.0.0.1:{$port}", $code, $message, 0.5);
         if ($Client === false) {
            return false;
         }
         stream_set_timeout($Client, 1);
         fwrite($Client, "GET / HTTP/1.1\r\nHost: 127.0.0.1:{$port}\r\nUpgrade: websocket\r\n"
            . "Connection: Upgrade\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n"
            . "Sec-WebSocket-Version: 13\r\n\r\n");
         $line = (string) fgets($Client, 128);
         fclose($Client);

         return str_starts_with($line, 'HTTP/1.1 101');
      };
      /** Wait until 4 workers, none of them in $Before, answer the handshake. */
      $Settle = static function (int $master, array $Before, float $seconds) use ($Workers, $Handshake): array {
         $deadline = hrtime(true) + (int) ($seconds * 1e9);
         do {
            $PIDs = $Workers($master);
            if (count($PIDs) === 4 && array_intersect($PIDs, $Before) === [] && $Handshake()) {
               return $PIDs;
            }
            usleep(50_000);
         } while (hrtime(true) < $deadline);

         return [];
      };

      $Process = proc_open(
         [PHP_BINARY, __FILE__, '--ws-bus-reload-probe', BOOTGLY_ROOT_BASE, (string) $port],
         [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
         $Pipes,
         BOOTGLY_ROOT_BASE,
      );
      $master = is_resource($Process) ? (int) proc_get_status($Process)['pid'] : 0;

      $Observed = [];
      try {
         $PIDs = $Settle($master, [], 8.0);
         // ! Settled: no reload is in flight
         usleep(200_000);
         $masterBefore = $Sockets($master);
         $workerBefore = $PIDs !== [] ? $Sockets($PIDs[0]) : 0;

         // @@ Three reloads, each answered by a whole new set of workers
         $answered = $PIDs !== [];
         for ($reload = 0; $reload < 3 && $answered; $reload++) {
            posix_kill($master, SIGUSR2);
            $PIDs = $Settle($master, $PIDs, 10.0);
            $answered = $PIDs !== [];
         }
         usleep(200_000);

         $Observed = [
            'served' => $masterBefore > 0 && $workerBefore > 0,
            'handshake after every reload' => $answered,
            'master socket growth' => $Sockets($master) - $masterBefore,
            'worker socket growth' => $PIDs !== [] ? $Sockets($PIDs[0]) - $workerBefore : null,
         ];
      }
      finally {
         // ! The whole session group; never PID 0/-1 (this runner's group)
         if ($master > 0) {
            posix_kill(-$master, SIGKILL);
         }
         if (is_resource($Process)) {
            proc_close($Process);
         }
         $cleanup = hrtime(true) + 1_000_000_000;
         while ($master > 0 && posix_kill(-$master, 0) && hrtime(true) < $cleanup) {
            usleep(10_000);
         }
         $Observed['group clean'] = $master > 0 && posix_kill(-$master, 0) === false;

         // @ This run's state inodes, by their literal prefix
         foreach ((array) @scandir(BOOTGLY_STORAGE_DIR . 'pids') as $file) {
            if (str_starts_with((string) $file, "BusReloadProbe.{$port}.")) {
               @unlink(BOOTGLY_STORAGE_DIR . "pids/{$file}");
            }
         }
      }

      yield new Assertion(description: 'three reloads leave the master and a fresh worker with the sockets they started with')
         ->expect($Observed, Op::Identical, [
            'served' => true,
            'handshake after every reload' => true,
            'master socket growth' => 0,
            'worker socket growth' => 0,
            'group clean' => true,
         ])
         ->assert();
   }),
);
