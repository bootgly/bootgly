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
 * A master that is PID 1 (a container without an init) reaps the orphans it
 * inherits — the background children a worker leaves behind — while no
 * SIGCHLD dispatch reaps them, and it still reforks a worker that dies.
 */
return new Test(
   description: 'A UDP master running as PID 1 reaps the orphans it inherits and still reforks a dead worker',
   skip: function_exists('posix_kill') === false
      || function_exists('proc_open') === false
      || is_executable('/usr/bin/unshare') === false
      // ! The capability on its own (AppArmor or a zero user.max_user_namespaces
      //   denies it): only its absence may skip — a server that fails inside
      //   a usable namespace must fail the case
      || (static function (): bool {
         $Probe = proc_open(
            ['/usr/bin/unshare', '-Urpf', '--mount-proc', '--kill-child', 'true'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $Pipes,
         );

         return is_resource($Probe) === false || proc_close($Probe) !== 0;
      })(),
   test: new Assertions(Case: function (): Generator {
      $Script = <<<'PHP'
require getenv('UDP_PID1_AUTOBOOT');

use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Interfaces\UDP_Server_CLI;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Configs;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Events;

// ! A spec-only class: its state inodes are this spec's alone to remove
final class Pid1Probe extends UDP_Server_CLI
{
   public static function boot (mixed $Environment): void
   {
   }
}

$Server = new Pid1Probe(Modes::Foreground);
$Server->configure(new Configs(host: '127.0.0.1', port: (int) getenv('UDP_PID1_PORT'), workers: 1));
$Server->on(Events::DatagramReceive, static function (string $input): string {
   if ($input === 'orphan') {
      exec('sleep 0.2 > /dev/null 2>&1 &');
   }
   if ($input === 'crash') {
      exit(3);
   }

   return json_encode(['pid' => getmypid()]);
});
$Server->start();
PHP;

      $Reservation = stream_socket_server('udp://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND);
      $name = is_resource($Reservation) ? (string) stream_socket_get_name($Reservation, false) : '';
      $port = (int) substr($name, (int) strrpos($name, ':') + 1);
      if (is_resource($Reservation)) {
         fclose($Reservation);
      }

      $Environment = (array) getenv();
      $Environment['UDP_PID1_AUTOBOOT'] = BOOTGLY_ROOT_DIR . 'autoboot.php';
      $Environment['UDP_PID1_PORT'] = (string) $port;
      // ! A new user + PID namespace: the master is PID 1 there, as in a
      //   container whose entrypoint execs the server without an init
      $Process = proc_open(
         ['/usr/bin/unshare', '-Urpf', '--mount-proc', '--kill-child', PHP_BINARY, '-r', $Script],
         [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
         $Pipes,
         BOOTGLY_ROOT_BASE,
         $Environment,
      );
      $unshare = is_resource($Process) ? (int) proc_get_status($Process)['pid'] : 0;

      $Client = stream_socket_server('udp://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND);
      stream_set_blocking($Client, false);
      /** Ask the worker once; the decoded reply or null. */
      $Ask = static function (string $payload, float $seconds = 0.3) use ($Client, $port): null|array {
         while (@stream_socket_recvfrom($Client, 65_535) !== false) {
            // drain late replies
         }
         stream_socket_sendto($Client, $payload, 0, "127.0.0.1:{$port}");
         $read = [$Client];
         $write = null;
         $except = null;
         if (@stream_select($read, $write, $except, 0, (int) ($seconds * 1e6)) !== 1) {
            return null;
         }
         $Data = json_decode((string) @stream_socket_recvfrom($Client, 65_535), true);

         return is_array($Data) ? $Data : null;
      };
      /** The host PIDs of a process's children. */
      $Children = static function (int $PID): array {
         $Found = [];
         foreach ((array) glob("/proc/{$PID}/task/*/children") as $file) {
            foreach (preg_split('/\s+/', trim((string) @file_get_contents((string) $file))) ?: [] as $child) {
               if ($child !== '') {
                  $Found[] = (int) $child;
               }
            }
         }

         return $Found;
      };

      $Observed = [];
      try {
         $First = null;
         $deadline = hrtime(true) + 6_000_000_000;
         while ($First === null && hrtime(true) < $deadline) {
            $First = $Ask('state', 0.2);
         }
         $master = $Children($unshare)[0] ?? 0;
         // ? The capability was probed: a master that never served is a failure
         $Observed['PID 1 master serving'] = $First !== null && $master > 0;
         if ($Observed['PID 1 master serving'] === false) {
            $Observed['orphans reaped'] = null;
            $Observed['dead worker reforked'] = null;
         }
         else {
            // @ 20 orphaned grandchildren, each gone 0.2 s later
            for ($index = 0; $index < 20; $index++) {
               $Ask('orphan');
            }
            usleep(2_000_000);
            $zombies = 0;
            $strays = 0;
            $worker = (int) ($First['pid'] ?? 0);
            foreach ($Children($master) as $child) {
               $state = preg_match('/^State:\s+(\S)/m', (string) @file_get_contents("/proc/{$child}/status"), $Match) === 1 ? $Match[1] : '';
               if ($state === 'Z') {
                  $zombies++;
               }
               if (preg_match('/^NSpid:\s+\d+\s+(\d+)/m', (string) @file_get_contents("/proc/{$child}/status"), $Match) === 1 && (int) $Match[1] !== $worker) {
                  $strays++;
               }
            }
            $Observed['orphans reaped'] = $zombies === 0 && $strays === 0;

            // @ A worker death is still reforked from the loop
            $Ask('crash');
            $Revived = null;
            $deadline = hrtime(true) + 5_000_000_000;
            while ($Revived === null && hrtime(true) < $deadline) {
               $Data = $Ask('state', 0.2);
               if ($Data !== null && ($Data['pid'] ?? 0) !== $worker) {
                  $Revived = $Data;
               }
            }
            $Observed['dead worker reforked'] = $Revived !== null;
         }
      }
      finally {
         if ($unshare > 0) {
            posix_kill($unshare, SIGKILL);
         }
         if (is_resource($Process)) {
            proc_close($Process);
         }
         fclose($Client);

         // @ This run's state inodes, by their literal prefix (the master never
         //   reached its teardown)
         foreach ((array) @scandir(BOOTGLY_STORAGE_DIR . 'pids') as $file) {
            if (str_starts_with((string) $file, "Pid1Probe.{$port}.")) {
               @unlink(BOOTGLY_STORAGE_DIR . "pids/{$file}");
            }
         }
      }

      yield new Assertion(description: 'as PID 1 the master reaps what it inherits and keeps reforking')
         ->expect($Observed, Op::Identical, [
            'PID 1 master serving' => true,
            'orphans reaped' => true,
            'dead worker reforked' => true,
         ])
         ->assert();
   }),
);
