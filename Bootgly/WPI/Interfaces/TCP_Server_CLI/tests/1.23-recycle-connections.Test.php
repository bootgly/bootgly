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
 * Worker recycling on the TCP transport, live: a Foreground server (two
 * workers, `maxRequests: 4`, `jitter: 0`) counts every accepted connection.
 * A worker stops accepting at its fourth connection: it announces its recycle
 * on its beacon and drains, the master forks its replacement at once on the
 * slot's listener — logged as a recycle, never a crash, never a crash-loop
 * backoff — and the retiring worker exits with `RECYCLE`. A replacement that
 * reaches its own fourth while its slot's previous worker still drains holds
 * its recycle — it accepts on — until that worker is gone, and says so when
 * it recycles: no worker answers past its limit otherwise. Waves of
 * concurrent connections opened across the recycle boundaries are all
 * answered: the connections the slot queues are accepted by its replacement.
 * The same server with `maxRequests: 0` never recycles, and neither does a
 * `Modes::Test` one with `maxRequests: 2` — not even with a `maxMemory` no
 * worker could honor, which a Test-mode server never refuses: its master
 * never reforks, so recycling there would only lose workers.
 */
return new Test(
   description: 'Recycling: a TCP worker recycles after maxRequests connections, without dropping one',
   skip: function_exists('posix_kill') === false
      || function_exists('posix_setsid') === false
      || function_exists('proc_open') === false,
   test: new Assertions(Case: function (): Generator {
      $Script = <<<'PHP'
$Session = posix_setsid();
if ($Session === false || $Session === -1) {
   exit(125);
}

require getenv('TCP_RECYCLE_AUTOBOOT');

use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Configs;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Events;

final class RecycleProbe extends TCP_Server_CLI
{
   public static function boot (mixed $Environment): void
   {
   }
}

$Server = new RecycleProbe(getenv('TCP_RECYCLE_MODE') === 'test' ? Modes::Test : Modes::Foreground);
$Server->configure(new Configs(
   host: '127.0.0.1',
   port: (int) getenv('TCP_RECYCLE_PORT'),
   workers: 2,
   maxRequests: (int) getenv('TCP_RECYCLE_MAX'),
   jitter: 0.0,
   maxMemory: (int) getenv('TCP_RECYCLE_MEMORY'),
));
$Server->on(Events::DataReceive, static function ($input): string {
   return (string) getmypid();
});
$Server->start();
// ! A Test-mode master returns from start(): keep it supervising
while (true) {
   pcntl_signal_dispatch();
   usleep(50_000);
}
PHP;

      /**
       * Boot one server; run $Drive against its port; return what it saw
       * plus the server log and whether its session group was left clean.
       *
       * @return array{0:array<string,mixed>,1:string,2:bool}
       */
      $Run = static function (int $max, Closure $Drive, string $mode = 'foreground', int $memory = 0) use ($Script): array {
         // @ Reserve a free loopback port.
         $Reservation = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
         $name = is_resource($Reservation) ? (string) stream_socket_get_name($Reservation, false) : '';
         $port = (int) substr($name, (int) strrpos($name, ':') + 1);
         if (is_resource($Reservation)) {
            fclose($Reservation);
         }

         $Environment = (array) getenv();
         $Environment['TCP_RECYCLE_AUTOBOOT'] = BOOTGLY_ROOT_DIR . 'autoboot.php';
         $Environment['TCP_RECYCLE_PORT'] = (string) $port;
         $Environment['TCP_RECYCLE_MAX'] = (string) $max;
         $Environment['TCP_RECYCLE_MODE'] = $mode;
         $Environment['TCP_RECYCLE_MEMORY'] = (string) $memory;
         $Process = proc_open(
            [PHP_BINARY, '-d', 'opcache.jit=0', '-r', $Script],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $Pipes,
            BOOTGLY_ROOT_BASE,
            $Environment,
         );
         $master = is_resource($Process) ? (int) proc_get_status($Process)['pid'] : 0;
         stream_set_blocking($Pipes[1], false);
         stream_set_blocking($Pipes[2], false);
         $output = '';
         $Read = static function () use ($Pipes, &$output): string {
            $output .= (string) stream_get_contents($Pipes[1]) . (string) stream_get_contents($Pipes[2]);

            return $output;
         };

         $Observed = [];
         $clean = false;
         try {
            // @ Wait until it serves
            $deadline = hrtime(true) + 8_000_000_000;
            do {
               $Read();
               $Client = @stream_socket_client("tcp://127.0.0.1:{$port}", $code, $message, 0.2);
               usleep(50_000);
            } while ($Client === false && hrtime(true) < $deadline);
            // ! The probe connection counts as one dispatch of whichever
            //   worker took it: close it unanswered and let the spec account
            //   for it
            if ($Client !== false) {
               fclose($Client);
            }
            $Observed = $Drive($port, $Read, $Client !== false);
            $Observed['master alive'] = is_resource($Process) && proc_get_status($Process)['running'];
            // ! Let the last drains finish so their log lines are read
            $settle = hrtime(true) + 1_500_000_000;
            while (hrtime(true) < $settle) {
               $Read();
               usleep(50_000);
            }
         }
         finally {
            // ! The whole session group; never PID 0/-1 (this runner's group).
            if ($master > 0) {
               posix_kill(-$master, SIGKILL);
            }
            $Read();
            foreach ($Pipes as $Pipe) {
               @fclose($Pipe);
            }
            if (is_resource($Process)) {
               proc_close($Process);
            }
            $cleanup = hrtime(true) + 1_000_000_000;
            while ($master > 0 && posix_kill(-$master, 0) && hrtime(true) < $cleanup) {
               usleep(10_000);
            }
            $clean = $master > 0 && posix_kill(-$master, 0) === false;
            foreach ((array) @scandir(BOOTGLY_STORAGE_DIR . 'pids') as $file) {
               if (str_starts_with((string) $file, "RecycleProbe.{$port}.")) {
                  @unlink(BOOTGLY_STORAGE_DIR . "pids/{$file}");
               }
            }
         }

         return [$Observed, $output, $clean];
      };

      /**
       * Open $count connections at once, then send and read each one.
       *
       * @return array<int,int> The answering worker PID per connection (0 = unanswered).
       */
      $Wave = static function (int $port, int $count, Closure $Read): array {
         $Clients = [];
         for ($index = 0; $index < $count; $index++) {
            $Clients[$index] = @stream_socket_client("tcp://127.0.0.1:{$port}", $code, $message, 2.0);
         }
         foreach ($Clients as $Client) {
            if ($Client !== false) {
               @fwrite($Client, 'pid');
            }
         }
         $PIDs = [];
         foreach ($Clients as $index => $Client) {
            $PIDs[$index] = 0;
            if ($Client === false) {
               continue;
            }
            // ! A connection queued behind a draining slot waits for the
            //   replacement worker: the drain polls once a second
            stream_set_timeout($Client, 6);
            $PIDs[$index] = (int) fread($Client, 64);
            fclose($Client);
            $Read();
         }

         return $PIDs;
      };

      // @ maxRequests: 4 — waves of 6 concurrent connections
      [$Observed, $output, $clean] = $Run(4, static function (int $port, Closure $Read, bool $up) use ($Wave): array {
         $PIDs = [];
         for ($wave = 0; $up && $wave < 5; $wave++) {
            $PIDs = [...$PIDs, ...$Wave($port, 6, $Read)];
         }
         $Counts = array_count_values(array_filter($PIDs));
         // # Past its limit only while held — its slot's previous worker still
         //   drained — and it says so once the go lets it recycle
         $Held = array_keys(array_filter($Counts, static fn (int $count): bool => $count > 4));
         $Silent = $Held;
         $deadline = hrtime(true) + 5_000_000_000;
         while ($Silent !== [] && hrtime(true) < $deadline) {
            usleep(50_000);
            $log = $Read();
            $Silent = array_filter(
               $Silent,
               static fn (int $PID): bool => str_contains($log, "Worker (PID: {$PID}) held its recycle") === false
            );
         }

         return [
            'server up' => $up,
            'every connection answered (30 of 30)' => count($PIDs) === 30 && in_array(0, $PIDs, true) === false,
            // ! Both first workers pass 4 of the 31 dispatches (30 + the
            //   probe); their replacements, held while they drain, take the
            //   rest — a recycle per slot per drain
            'workers replaced (>= 3 distinct PIDs)' => count($Counts) >= 3,
            'a worker answered past its limit only when held, and said so' => $Counts !== [] && $Silent === [],
         ];
      });
      $recycles = preg_match_all('/recycling, forking its replacement|recycled, reforking/', $output);
      $Observed['recycles logged by the master (>= 2)'] = $recycles >= 2;
      $Observed['each drain says why'] = substr_count($output, 'connections reached its limit of 4') >= 2
         && substr_count($output, 'connections reached its limit of 4') >= $recycles;
      $Observed['no crash logged'] = str_contains($output, 'crashed') === false;
      $Observed['no crash-loop backoff'] = str_contains($output, 'times in a row') === false;
      $Observed['group clean'] = $clean;

      yield new Assertion(description: 'past maxRequests connections a worker recycles: no connection dropped, no crash, no backoff')
         ->expect($Observed, Op::Identical, [
            'server up' => true,
            'every connection answered (30 of 30)' => true,
            'workers replaced (>= 3 distinct PIDs)' => true,
            'a worker answered past its limit only when held, and said so' => true,
            'master alive' => true,
            'recycles logged by the master (>= 2)' => true,
            'each drain says why' => true,
            'no crash logged' => true,
            'no crash-loop backoff' => true,
            'group clean' => true,
         ])
         ->assert();

      // @ maxRequests: 0 — the same traffic never recycles
      [$Observed, $output, $clean] = $Run(0, static function (int $port, Closure $Read, bool $up) use ($Wave): array {
         $PIDs = [];
         for ($wave = 0; $up && $wave < 5; $wave++) {
            $PIDs = [...$PIDs, ...$Wave($port, 6, $Read)];
         }
         $Counts = array_count_values(array_filter($PIDs));

         return [
            'server up' => $up,
            'every connection answered (30 of 30)' => count($PIDs) === 30 && in_array(0, $PIDs, true) === false,
            'the same two workers throughout' => count($Counts) <= 2,
         ];
      });
      $Observed['nothing recycled'] = str_contains($output, 'recycl') === false;
      $Observed['group clean'] = $clean;

      yield new Assertion(description: 'maxRequests 0 never recycles')
         ->expect($Observed, Op::Identical, [
            'server up' => true,
            'every connection answered (30 of 30)' => true,
            'the same two workers throughout' => true,
            'master alive' => true,
            'nothing recycled' => true,
            'group clean' => true,
         ])
         ->assert();

      // @ Modes::Test, maxRequests: 2 and an unreachable maxMemory — its
      //   workers never count, and its start never refuses
      [$Observed, $output, $clean] = $Run(2, static function (int $port, Closure $Read, bool $up) use ($Wave): array {
         $PIDs = [];
         for ($round = 0; $up && $round < 8; $round++) {
            $PIDs = [...$PIDs, ...$Wave($port, 1, $Read)];
         }
         $Counts = array_count_values(array_filter($PIDs));

         return [
            'server up' => $up,
            'every connection answered (8 of 8, past 2 per worker)' => count($PIDs) === 8 && in_array(0, $PIDs, true) === false,
            'the same two workers throughout' => count($Counts) <= 2,
         ];
      }, 'test', 1);
      $Observed['nothing recycled'] = str_contains($output, 'recycl') === false;
      $Observed['group clean'] = $clean;

      yield new Assertion(description: 'a Test-mode server never recycles, and starts with any maxMemory')
         ->expect($Observed, Op::Identical, [
            'server up' => true,
            'every connection answered (8 of 8, past 2 per worker)' => true,
            'the same two workers throughout' => true,
            'master alive' => true,
            'nothing recycled' => true,
            'group clean' => true,
         ])
         ->assert();
   }),
);
