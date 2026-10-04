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
 * A refork the kernel refuses (RLIMIT_NPROC here; a cgroup pids.max or
 * ENOMEM alike) must leave the master serving with the workers it still has,
 * keep the slot queued and refork it once forks succeed again.
 */
return new Test(
   description: 'TCP-24: a refused refork keeps the master and its slot, and the slot returns once forks succeed',
   // ! RLIMIT_NPROC binds no process with CAP_SYS_RESOURCE: as root the
   //   refusal can never be produced, and a pass would prove nothing
   skip: function_exists('posix_kill') === false
      || function_exists('posix_setsid') === false
      || function_exists('posix_setrlimit') === false
      || function_exists('proc_open') === false
      || posix_geteuid() === 0,
   test: new Assertions(Case: function (): Generator {
      $Script = <<<'PHP'
$Session = posix_setsid();
if ($Session === false || $Session === -1) {
   exit(125);
}

require getenv('UDP_REVIVE_AUTOBOOT');

use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Interfaces\UDP_Server_CLI;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Configs;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Events;

final class RefusalProbe extends UDP_Server_CLI
{
   public static function boot (mixed $Environment): void
   {
   }

   protected function revive (): void
   {
      // @ The master's own soft process limit, driven by the spec: an
      //   unprivileged process may lower it and raise it back to the hard one
      $hard = posix_getrlimit()['hard maxproc'] ?? 'unlimited';
      $hard = $hard === 'unlimited' ? POSIX_RLIMIT_INFINITY : (int) $hard;
      $limit = (string) getenv('UDP_REVIVE_LIMIT');
      // ! A long-lived master: never a cached stat
      clearstatcache();
      if (is_file($limit)) {
         posix_setrlimit(POSIX_RLIMIT_NPROC, 1, $hard);
         @touch("{$limit}.applied");
      }
      else {
         posix_setrlimit(POSIX_RLIMIT_NPROC, $hard, $hard);
      }

      parent::revive();
   }
}

$Server = new RefusalProbe(Modes::Foreground);
$Server->configure(new Configs(
   host: '127.0.0.1',
   port: (int) getenv('UDP_REVIVE_PORT'),
   workers: 2,
));
$Server->on(Events::DatagramReceive, static fn (string $input): string => (string) getmypid());
$Server->start();
PHP;

      // @ Reserve a free loopback port.
      $Reservation = stream_socket_server('udp://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND);
      $name = is_resource($Reservation) ? (string) stream_socket_get_name($Reservation, false) : '';
      $port = (int) substr($name, (int) strrpos($name, ':') + 1);
      if (is_resource($Reservation)) {
         fclose($Reservation);
      }
      $limit = BOOTGLY_STORAGE_DIR . "revive-refusal-{$port}.limit";
      @unlink($limit);
      @unlink("{$limit}.applied");

      // ! A fixed pool of distinct sources
      /** @var array<int,resource> $Clients */
      $Clients = [];
      while (count($Clients) < 16) {
         $Client = stream_socket_server('udp://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND);
         if (is_resource($Client) === false) {
            break;
         }
         stream_set_blocking($Client, false);
         $Clients[] = $Client;
      }

      $Environment = (array) getenv();
      $Environment['UDP_REVIVE_AUTOBOOT'] = BOOTGLY_ROOT_DIR . 'autoboot.php';
      $Environment['UDP_REVIVE_PORT'] = (string) $port;
      $Environment['UDP_REVIVE_LIMIT'] = $limit;
      $Process = proc_open(
         [PHP_BINARY, '-r', $Script],
         [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
         $Pipes,
         BOOTGLY_ROOT_BASE,
         $Environment,
      );
      $master = is_resource($Process) ? (int) proc_get_status($Process)['pid'] : 0;
      stream_set_blocking($Pipes[1], false);
      stream_set_blocking($Pipes[2], false);
      $output = '';
      $Drain = static function () use ($Pipes, &$output): void {
         $output .= (string) stream_get_contents($Pipes[1]) . (string) stream_get_contents($Pipes[2]);
      };
      /** Ask from source $index; the answering worker PID or 0. */
      $Ask = static function (int $index) use (&$Clients, $port, $Drain): int {
         $Drain();
         $Client = $Clients[$index % count($Clients)];
         while (@stream_socket_recvfrom($Client, 65_535) !== false) {
            // drain late replies
         }
         stream_socket_sendto($Client, 'pid', 0, "127.0.0.1:{$port}");
         $read = [$Client];
         $write = null;
         $except = null;
         if (@stream_select($read, $write, $except, 0, 150_000) !== 1) {
            return 0;
         }

         return (int) @stream_socket_recvfrom($Client, 65_535);
      };
      /** The distinct worker PIDs answering within $seconds (stops at 2). */
      $Census = static function (float $seconds) use ($Ask): array {
         $PIDs = [];
         $deadline = hrtime(true) + (int) ($seconds * 1e9);
         for ($index = 0; count($PIDs) < 2 && hrtime(true) < $deadline; $index++) {
            $PID = $Ask($index);
            if ($PID > 0) {
               $PIDs[$PID] = true;
            }
         }
         $PIDs = array_keys($PIDs);
         sort($PIDs);

         return $PIDs;
      };
      $Idle = static function (float $seconds) use ($Drain): void {
         $deadline = hrtime(true) + (int) ($seconds * 1e9);
         while (hrtime(true) < $deadline) {
            $Drain();
            usleep(20_000);
         }
      };
      $Alive = static fn (): bool => is_resource($Process) && proc_get_status($Process)['running'];

      $Observed = [];
      try {
         $Initial = $Census(6.0);
         $Observed['initial workers'] = count($Initial);

         // @ Limit the master, then lose a worker
         touch($limit);
         $deadline = hrtime(true) + 3_000_000_000;
         while (is_file("{$limit}.applied") === false && hrtime(true) < $deadline) {
            usleep(20_000);
         }
         $Observed['limit applied'] = is_file("{$limit}.applied");
         $victim = $Initial[0] ?? 0;
         if ($victim > 0) {
            posix_kill($victim, SIGKILL);
         }
         $Idle(2.5);
         $Drain();
         $Observed['master survives the refused fork'] = $Alive();
         $Observed['survivor serves'] = in_array($Initial[1] ?? -1, $Census(1.0), true);
         $Observed['refusal logged once'] = substr_count($output, 'could not be reforked');

         // @ Lift the limit: the queued slot is reforked
         @unlink($limit);
         $Replaced = false;
         $deadline = hrtime(true) + 4_000_000_000;
         do {
            $PIDs = $Census(0.5);
            $Replaced = count($PIDs) === 2 && in_array($victim, $PIDs, true) === false;
         } while ($Replaced === false && hrtime(true) < $deadline);
         $Observed['slot reforked once forks succeed'] = $Replaced;
         $Observed['master alive'] = $Alive();
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
         @unlink($limit);
         @unlink("{$limit}.applied");
         foreach ((array) @scandir(BOOTGLY_STORAGE_DIR . 'pids') as $file) {
            if (str_starts_with((string) $file, "RefusalProbe.{$port}.")) {
               @unlink(BOOTGLY_STORAGE_DIR . "pids/{$file}");
            }
         }
      }

      yield new Assertion(description: 'a refused refork neither ends the master nor loses the slot')
         ->expect($Observed, Op::Identical, [
            'initial workers' => 2,
            'limit applied' => true,
            'master survives the refused fork' => true,
            'survivor serves' => true,
            'refusal logged once' => 1,
            'slot reforked once forks succeed' => true,
            'master alive' => true,
            'group clean' => true,
         ])
         ->assert();
   }),
);
