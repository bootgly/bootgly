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
 * The console modes supervise like Daemon and Foreground: a flood of worker
 * crashes and a worker that exits 0 are reforked, and the master survives —
 * also while an idle Interactive console waits in readline() on a terminal.
 */
return new Test(
   description: 'UDP-21: Interactive and Monitor masters refork crashed and exited workers instead of stopping or losing them',
   skip: function_exists('posix_kill') === false
      || function_exists('posix_setsid') === false
      || function_exists('proc_open') === false,
   test: new Assertions(Case: function (): Generator {
      $Script = <<<'PHP'
$Session = posix_setsid();
if ($Session === false || $Session === -1) {
   exit(125);
}

require getenv('UDP_CONSOLE_AUTOBOOT');

use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Interfaces\UDP_Server_CLI;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Configs;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Events;

final class ConsoleProbe extends UDP_Server_CLI
{
   public static function boot (mixed $Environment): void
   {
   }
}

$Server = new ConsoleProbe(getenv('UDP_CONSOLE_MODE') === 'Monitor' ? Modes::Monitor : Modes::Interactive);
$Server->configure(new Configs(
   host: '127.0.0.1',
   port: (int) getenv('UDP_CONSOLE_PORT'),
   workers: 4,
));
$Server->on(Events::DatagramReceive, static function (string $input): string {
   if ($input === 'crash') {
      exit(3);
   }
   if ($input === 'quit') {
      exit(0);
   }

   return (string) getmypid();
});
$Server->start();
PHP;

      /**
       * Run one session-isolated console server and drive it.
       *
       * $terminal: the master's stdio is a pseudo-terminal (readline() blocks)
       * instead of /dev/null (readline() returns at once).
       * $flood: a crash flood, then an exit 0; otherwise one crash and one
       * exit 0, each after an idle second and bounded in time.
       *
       * @return array<string,mixed>
       */
      $Run = static function (string $mode, bool $terminal, bool $flood) use ($Script): array {
         // @ Reserve a free loopback port.
         $Reservation = stream_socket_server('udp://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND);
         $name = is_resource($Reservation) ? (string) stream_socket_get_name($Reservation, false) : '';
         $port = (int) substr($name, (int) strrpos($name, ':') + 1);
         if (is_resource($Reservation)) {
            fclose($Reservation);
         }

         // ! A fixed pool of distinct sources: fresh sources on every probe
         //   would fill the per-IP admission ceiling of each worker
         /** @var array<int,resource> $Clients */
         $Clients = [];
         /** @var array<string,true> $Names */
         $Names = ["127.0.0.1:{$port}" => true];
         while (count($Clients) < 48) {
            $Client = stream_socket_server('udp://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND);
            if (is_resource($Client) === false) {
               break;
            }
            $source = (string) stream_socket_get_name($Client, false);
            // ? PHP binds with SO_REUSEADDR: two sockets can share one port
            if (isSet($Names[$source])) {
               fclose($Client);
               continue;
            }
            $Names[$source] = true;
            stream_set_blocking($Client, false);
            $Clients[] = $Client;
         }

         $Environment = (array) getenv();
         $Environment['UDP_CONSOLE_AUTOBOOT'] = BOOTGLY_ROOT_DIR . 'autoboot.php';
         $Environment['UDP_CONSOLE_PORT'] = (string) $port;
         $Environment['UDP_CONSOLE_MODE'] = $mode;
         $Process = proc_open(
            [PHP_BINARY, '-r', $Script],
            $terminal
               ? [0 => ['pty'], 1 => ['pty'], 2 => ['pty']]
               : [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $Pipes,
            BOOTGLY_ROOT_BASE,
            $Environment,
         );
         $master = is_resource($Process) ? (int) proc_get_status($Process)['pid'] : 0;
         foreach ($Pipes as $Pipe) {
            stream_set_blocking($Pipe, false);
         }

         /** Drain the terminal, so the master never blocks on its own output. */
         $Drain = static function () use ($Pipes): void {
            if (isSet($Pipes[1])) {
               while (is_string($chunk = @fread($Pipes[1], 65_536)) && $chunk !== '') {
                  // discard
               }
            }
         };
         /** Wait $seconds without touching the server, draining the terminal. */
         $Idle = static function (float $seconds) use ($Drain): void {
            $deadline = hrtime(true) + (int) ($seconds * 1e9);
            while (hrtime(true) < $deadline) {
               $Drain();
               usleep(20_000);
            }
         };
         /** Send one datagram from source $index; its reply (a worker PID) or 0. */
         $Ask = static function (int $index, string $payload, int $milliseconds = 150) use (&$Clients, $port, $Drain): int {
            $Drain();
            $Client = $Clients[$index % count($Clients)];
            while (@stream_socket_recvfrom($Client, 65_535) !== false) {
               // drain late replies
            }
            stream_socket_sendto($Client, $payload, 0, "127.0.0.1:{$port}");
            if ($milliseconds === 0) {
               return 0;
            }
            $read = [$Client];
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, 0, $milliseconds * 1_000) !== 1) {
               return 0;
            }

            return (int) @stream_socket_recvfrom($Client, 65_535);
         };
         /** The worker PIDs answering the pool, until 4 or the bound. */
         $Census = static function (float $seconds) use ($Ask): array {
            $PIDs = [];
            $deadline = hrtime(true) + (int) ($seconds * 1e9);
            for ($index = 0; count($PIDs) < 4 && hrtime(true) < $deadline; $index++) {
               $PID = $Ask($index, 'state');
               if ($PID > 0) {
                  $PIDs[$PID] = true;
               }
            }
            $PIDs = array_keys($PIDs);
            sort($PIDs);

            return $PIDs;
         };
         /** Whether 4 workers serve, one of them not in $Before, within $seconds. */
         $Replace = static function (array $Before, float $seconds) use ($Census): bool {
            $deadline = hrtime(true) + (int) ($seconds * 1e9);
            do {
               $PIDs = $Census(0.5);
               if (count($PIDs) === 4 && array_diff($PIDs, $Before) !== []) {
                  return true;
               }
            } while (hrtime(true) < $deadline);

            return false;
         };
         /** Whether the master process is still running. */
         $Alive = static fn (): bool => is_resource($Process) && proc_get_status($Process)['running'];

         $Observed = [];
         try {
            $Initial = $Census(6.0);
            $Observed['initial workers'] = count($Initial);

            if ($flood) {
               // @ A crash flood: every worker dies, close together
               for ($index = 0; $index < 32; $index++) {
                  $Ask($index, 'crash', 0);
                  usleep(2_000);
               }
               $Idle(0.3);
               $Flooded = $Census(8.0);
               $Observed['master survives a crash flood'] = $Alive();
               $Observed['workers after the flood'] = count($Flooded);

               // @ A worker that exits 0 is replaced too
               $Ask(0, 'quit', 0);
               $Idle(0.3);
               $Observed['exited worker replaced'] = $Replace($Flooded, 8.0);
               $Observed['master survives an exit 0'] = $Alive();
            }
            else {
               // @ An idle console (readline() blocked on the terminal): one
               //   crash, then one exit 0, each reforked without a keystroke
               $Idle(1.2);
               $Ask(0, 'crash', 0);
               $Observed['crash reforked while idle'] = $Replace($Initial, 2.5);
               $After = $Census(2.0);

               $Idle(1.2);
               $Ask(1, 'quit', 0);
               $Observed['exit 0 reforked while idle'] = $Replace($After, 2.5);
               $Observed['master alive'] = $Alive();
            }
         }
         finally {
            // ! The whole session group; never PID 0/-1 (this runner's group).
            if ($master > 0) {
               posix_kill(-$master, SIGKILL);
            }
            foreach ($Pipes as $Pipe) {
               @fclose($Pipe);
            }
            if (is_resource($Process)) {
               proc_close($Process);
            }
            foreach ($Clients as $Client) {
               fclose($Client);
            }
            $cleanup = hrtime(true) + 500_000_000;
            while ($master > 0 && posix_kill(-$master, 0) && hrtime(true) < $cleanup) {
               usleep(10_000);
            }
            $Observed['group clean'] = $master > 0 && posix_kill(-$master, 0) === false;

            // @ This run's state inodes, by their literal prefix (the master never
            //   reached its teardown)
            foreach ((array) @scandir(BOOTGLY_STORAGE_DIR . 'pids') as $file) {
               if (str_starts_with((string) $file, "ConsoleProbe.{$port}.")) {
                  @unlink(BOOTGLY_STORAGE_DIR . "pids/{$file}");
               }
            }
         }

         return $Observed;
      };

      $Flooded = [
         'initial workers' => 4,
         'master survives a crash flood' => true,
         'workers after the flood' => 4,
         'exited worker replaced' => true,
         'master survives an exit 0' => true,
         'group clean' => true,
      ];

      // @ Monitor never reads input
      yield new Assertion(description: 'Monitor reforks a crash flood and an exited worker and keeps the master')
         ->expect($Run('Monitor', false, true), Op::Identical, $Flooded)
         ->assert();

      // ? Interactive needs readline()
      if (function_exists('readline') === false) {
         yield (new Assertion(description: 'Interactive legs: readline() is not available'))->skip();

         return;
      }
      yield new Assertion(description: 'Interactive reforks a crash flood and an exited worker and keeps the master')
         ->expect($Run('Interactive', false, true), Op::Identical, $Flooded)
         ->assert();

      // ? A pseudo-terminal, and libedit: GNU readline retries its read on a
      //   SIGCHLD, so a refork there waits for a keystroke (filed, open)
      $terminal = false;
      if (defined('READLINE_LIB') && READLINE_LIB === 'libedit') {
         try {
            $Probe = proc_open(['true'], [0 => ['pty'], 1 => ['pty'], 2 => ['pty']], $Ends);
            $terminal = is_resource($Probe) && proc_close($Probe) === 0;
         }
         catch (Throwable) {
            $terminal = false;
         }
      }
      if ($terminal === false) {
         yield (new Assertion(description: 'Interactive terminal leg: no pseudo-terminal or no libedit here'))->skip();

         return;
      }
      yield new Assertion(description: 'an idle Interactive console on a terminal reforks a crashed and an exited worker without a keystroke')
         ->expect($Run('Interactive', true, false), Op::Identical, [
            'initial workers' => 4,
            'crash reforked while idle' => true,
            'exit 0 reforked while idle' => true,
            'master alive' => true,
            'group clean' => true,
         ])
         ->assert();
   }),
);
