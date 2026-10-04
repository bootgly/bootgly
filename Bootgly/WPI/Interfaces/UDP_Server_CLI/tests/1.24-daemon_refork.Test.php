<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;


/**
 * A Daemon master — the default mode of every project — closed its STDIN
 * when it detached, and still reforks a worker that dies: by a signal or
 * through its own exit. The replacement serves.
 */
return new Test(
   description: 'TCP-24: a UDP Daemon master reforks a worker killed by a signal or by its own exit, and the replacement serves',
   skip: function_exists('posix_kill') === false
      || function_exists('posix_setsid') === false
      || function_exists('proc_open') === false,
   test: new Assertions(Case: function (): Generator {
      $Script = <<<'PHP'
$Session = posix_setsid();
if ($Session === false || $Session === -1) {
   exit(125);
}

require getenv('UDP_DAEMON_AUTOBOOT');

use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Interfaces\UDP_Server_CLI;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Configs;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Events;

final class DaemonProbe extends UDP_Server_CLI
{
   public static function boot (mixed $Environment): void
   {
   }
}

$Server = new DaemonProbe(Modes::Daemon);
$Server->configure(new Configs(
   host: '127.0.0.1',
   port: (int) getenv('UDP_DAEMON_PORT'),
   workers: 1,
));
$Server->on(Events::DatagramReceive, static function (string $input): string {
   if ($input === 'crash') {
      exit(3);
   }

   return getmypid() . ' ' . posix_getppid();
});
$Server->start();
PHP;

      // @ Reserve a free loopback port.
      $Reservation = stream_socket_server('udp://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND);
      $name = is_resource($Reservation) ? (string) stream_socket_get_name($Reservation, false) : '';
      $port = (int) substr($name, (int) strrpos($name, ':') + 1);
      if (is_resource($Reservation)) {
         fclose($Reservation);
      }

      $Environment = (array) getenv();
      $Environment['UDP_DAEMON_AUTOBOOT'] = BOOTGLY_ROOT_DIR . 'autoboot.php';
      $Environment['UDP_DAEMON_PORT'] = (string) $port;
      $Process = proc_open(
         [PHP_BINARY, '-r', $Script],
         [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
         $Pipes,
         BOOTGLY_ROOT_BASE,
         $Environment,
      );
      $launcher = is_resource($Process) ? (int) proc_get_status($Process)['pid'] : 0;

      $Client = stream_socket_server('udp://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND);
      stream_set_blocking($Client, false);
      /** [worker PID, its parent PID] of the worker answering within $seconds, or [0, 0]. */
      $Serving = static function (float $seconds) use ($Client, $port): array {
         $deadline = hrtime(true) + (int) ($seconds * 1e9);
         do {
            while (@stream_socket_recvfrom($Client, 65_535) !== false) {
               // drain late replies
            }
            stream_socket_sendto($Client, 'pid', 0, "127.0.0.1:{$port}");
            $Read = [$Client];
            $Write = null;
            $Except = null;
            if (@stream_select($Read, $Write, $Except, 0, 200_000) === 1
               && preg_match('/^(\d+) (\d+)$/', (string) @stream_socket_recvfrom($Client, 65_535), $Match) === 1) {
               return [(int) $Match[1], (int) $Match[2]];
            }
            usleep(50_000);
         } while (hrtime(true) < $deadline);

         return [0, 0];
      };
      /** Whether a worker other than $PID serves within $seconds. */
      $Replace = static function (int $PID, float $seconds) use ($Serving): int {
         $deadline = hrtime(true) + (int) ($seconds * 1e9);
         do {
            [$serving] = $Serving(0.5);
            if ($serving > 0 && $serving !== $PID) {
               return $serving;
            }
         } while (hrtime(true) < $deadline);

         return 0;
      };

      $Observed = [];
      $daemon = 0;
      try {
         // @ The launcher returns once the daemon is ready
         $deadline = hrtime(true) + 10_000_000_000;
         $launched = '';
         while (is_resource($Process) && proc_get_status($Process)['running'] && hrtime(true) < $deadline) {
            usleep(20_000);
         }
         $launched = (string) stream_get_contents($Pipes[1]) . (string) stream_get_contents($Pipes[2]);
         // ! The daemon's PID from the launcher itself, so a daemon that never
         //   serves is still torn down
         if (preg_match('/Daemon started \(PID: (\d+)\)/', $launched, $Match) === 1) {
            $daemon = (int) $Match[1];
         }
         [$worker, $parent] = $Serving(6.0);
         $daemon = $daemon > 0 ? $daemon : $parent;
         $Observed['daemon serves'] = $worker > 0 && $parent === $daemon && $daemon !== $launcher;

         // @ A worker killed by a signal is replaced
         if ($worker > 0) {
            posix_kill($worker, SIGKILL);
         }
         $second = $Replace($worker, 4.0);
         $Observed['a killed worker is replaced'] = $second > 0;

         // @ A worker that exits through the application is replaced
         stream_socket_sendto($Client, 'crash', 0, "127.0.0.1:{$port}");
         $Observed['an exited worker is replaced'] = $Replace($second, 4.0) > 0;
      }
      finally {
         // ! The daemon leads its own session; never PID 0/-1 (this runner's group)
         if ($daemon > 0) {
            posix_kill(-$daemon, SIGKILL);
         }
         if ($launcher > 0) {
            @posix_kill(-$launcher, SIGKILL);
         }
         if (is_resource($Process)) {
            foreach ($Pipes as $Pipe) {
               @fclose($Pipe);
            }
            proc_close($Process);
         }
         fclose($Client);
         $cleanup = hrtime(true) + 1_000_000_000;
         while ($daemon > 0 && posix_kill(-$daemon, 0) && hrtime(true) < $cleanup) {
            usleep(10_000);
         }
         $Observed['group clean'] = $daemon > 0 && posix_kill(-$daemon, 0) === false;

         // @ This run's state inodes, by their literal prefix
         foreach ((array) @scandir(BOOTGLY_STORAGE_DIR . 'pids') as $file) {
            if (str_starts_with((string) $file, "DaemonProbe.{$port}.")) {
               @unlink(BOOTGLY_STORAGE_DIR . "pids/{$file}");
            }
         }
      }

      yield new Assertion(description: 'the UDP Daemon master replaces a killed and an exited worker')
         ->expect($Observed, Op::Identical, [
            'daemon serves' => true,
            'a killed worker is replaced' => true,
            'an exited worker is replaced' => true,
            'group clean' => true,
         ])
         ->assert();
   }),
);
