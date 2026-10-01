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
use Bootgly\WPI\Interfaces\UDP_Server_CLI;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Configs;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Events;


// ! Embedded fixture: reload() replays this exact file through pcntl_exec.
if (
   realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)
   && ($_SERVER['argv'][1] ?? null) === '--udp-reload-probe'
) {
   $root = rtrim((string) ($_SERVER['argv'][2] ?? ''), '/');
   $port = (int) ($_SERVER['argv'][3] ?? 0);
   if (realpath("{$root}/autoboot.php") === false || $port < 1024 || $port > 65535) {
      exit(2);
   }
   // ? First launch only — the replayed image already leads its session and
   //   must run under whatever mask reload() handed it (never re-applied,
   //   or the replay would mask the very inheritance under test).
   $session = @posix_setsid();
   if ($session !== false && $session !== -1) {
      // @ The launcher's own mask: every worker serves under exactly this.
      pcntl_sigprocmask(SIG_SETMASK, [SIGWINCH]);
   }

   $_SERVER['SCRIPT_FILENAME'] = '';
   require "{$root}/autoboot.php";
   Display::show(Display::NONE);

   $Server = new UDP_Server_CLI(Modes::Foreground);
   $Server->configure(new Configs(host: '127.0.0.1', port: $port, workers: 1));
   $Server->on(Events::DatagramReceive, static function (string $input): string {
      $Mask = [];
      pcntl_sigprocmask(SIG_BLOCK, [SIGWINCH], $Mask);
      pcntl_sigprocmask(SIG_SETMASK, $Mask);
      sort($Mask);

      return json_encode(['pid' => getmypid(), 'mask' => $Mask]);
   });
   $Server->start();
   exit(0);
}


return new Test(
   description: 'A reloaded UDP master and its workers keep the launcher signal mask',
   skip: function_exists('posix_kill') === false
      || function_exists('posix_setsid') === false
      || function_exists('proc_open') === false
      || is_readable('/proc/self/status') === false,
   test: new Assertions(Case: function (): Generator {
      /** A free loopback UDP port. */
      $Reserve = static function (): int {
         $Reservation = stream_socket_server('udp://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND);
         $name = is_resource($Reservation) ? (string) stream_socket_get_name($Reservation, false) : '';
         if (is_resource($Reservation)) {
            fclose($Reservation);
         }

         return (int) substr($name, (int) strrpos($name, ':') + 1);
      };

      $Client = stream_socket_server('udp://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND);
      stream_set_blocking($Client, false);
      /** Poll the worker on $port until $Accept takes a reply, within a hard deadline. */
      $Poll = static function (int $port, Closure $Accept, float $seconds) use ($Client): null|array {
         $deadline = hrtime(true) + (int) ($seconds * 1e9);
         do {
            while (@stream_socket_recvfrom($Client, 65_535) !== false) {
               // drain late replies
            }
            stream_socket_sendto($Client, 'state', 0, "127.0.0.1:{$port}");
            $read = [$Client];
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, 0, 200_000) === 1) {
               $Data = json_decode((string) @stream_socket_recvfrom($Client, 65_535), true);
               if (is_array($Data) && $Accept($Data)) {
                  return $Data;
               }
            }
         } while (hrtime(true) < $deadline);

         return null;
      };
      /** The SigBlk mask /proc reports for one PID, as a signal list. */
      $Blocked = static function (int $PID): null|array {
         $status = $PID > 0 ? @file_get_contents("/proc/{$PID}/status") : false;
         if (is_string($status) === false || preg_match('/^SigBlk:\s*([0-9a-f]+)/m', $status, $Match) !== 1) {
            return null;
         }
         $bits = (int) hexdec(substr($Match[1], -15));
         $Signals = [];
         for ($signal = 1; $signal <= 60; $signal++) {
            if ($bits & (1 << ($signal - 1))) {
               $Signals[] = $signal;
            }
         }

         return $Signals;
      };
      /** Launch the embedded probe on $port: [process, master PID]. */
      $Launch = static function (int $port): array {
         $Process = proc_open(
            [PHP_BINARY, __FILE__, '--udp-reload-probe', BOOTGLY_ROOT_BASE, (string) $port],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $Pipes,
            BOOTGLY_ROOT_BASE,
         );

         return [$Process, is_resource($Process) ? (int) proc_get_status($Process)['pid'] : 0];
      };
      /** Whether $master is gone within $seconds (reaping the exited launcher). */
      $Gone = static function (mixed $Process, int $master, float $seconds): bool {
         $deadline = hrtime(true) + (int) ($seconds * 1e9);
         while ($master > 0 && posix_kill($master, 0) && hrtime(true) < $deadline) {
            if (is_resource($Process)) {
               proc_get_status($Process); // reap the exited launcher
            }
            usleep(10_000);
         }

         return $master > 0 && (
            posix_kill($master, 0) === false || (is_resource($Process) && proc_get_status($Process)['running'] === false)
         );
      };
      /** Tear the whole session group down: true when nothing is left. */
      $Teardown = static function (mixed $Process, int $master): bool {
         if ($master > 0) {
            posix_kill(-$master, SIGKILL);
         }
         if (is_resource($Process)) {
            proc_close($Process);
         }
         $cleanup = hrtime(true) + 500_000_000;
         while ($master > 0 && posix_kill(-$master, 0) && hrtime(true) < $cleanup) {
            usleep(10_000);
         }

         return $master > 0 && posix_kill(-$master, 0) === false;
      };

      $Observed = [];
      // @ A plain reload: the fresh image and its workers keep the launcher mask
      $port = $Reserve();
      [$Process, $master] = $Launch($port);
      try {
         $Before = $Poll($port, static fn (array $Data): bool => true, 5.0);
         if ($master > 0) {
            posix_kill($master, SIGUSR2);
         }
         $After = $Poll($port, static fn (array $Data): bool => $Data['pid'] !== ($Before['pid'] ?? 0), 6.0);
         $Observed = [
            'worker mask before' => $Before['mask'] ?? null,
            'reloaded worker mask' => $After['mask'] ?? null,
            'reloaded master mask' => $Blocked($master),
         ];
         // @ The reloaded master still answers SIGTERM.
         if ($master > 0) {
            posix_kill($master, SIGTERM);
         }
         $Observed['reloaded master exits on SIGTERM'] = $Gone($Process, $master, 2.0);
      }
      finally {
         $Observed['group clean'] = $Teardown($Process, $master);
      }

      // @ A stop that arrives while reload() runs is honoured — never consumed
      //   by the mask reset and discarded by the exec
      $port = $Reserve();
      [$Process, $master] = $Launch($port);
      try {
         $Served = $Poll($port, static fn (array $Data): bool => true, 5.0);
         if ($master > 0 && $Served !== null) {
            posix_kill($master, SIGUSR2);
            usleep(20_000);
            posix_kill($master, SIGTERM);
         }
         $Observed['stop during reload honoured'] = $Served !== null && $Gone($Process, $master, 2.5);
      }
      finally {
         $Observed['second group clean'] = $Teardown($Process, $master);
         fclose($Client);
      }

      yield new Assertion(description: 'reload re-execs under the launcher mask, never the dispatch mask, and never swallows a stop')
         ->expect($Observed, Op::Identical, [
            'worker mask before' => [SIGWINCH],
            'reloaded worker mask' => [SIGWINCH],
            'reloaded master mask' => [SIGWINCH],
            'reloaded master exits on SIGTERM' => true,
            'group clean' => true,
            'stop during reload honoured' => true,
            'second group clean' => true,
         ])
         ->assert();
   }),
);
