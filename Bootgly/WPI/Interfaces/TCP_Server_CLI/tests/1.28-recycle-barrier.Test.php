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
 * A worker that recycles inside a node's startup barrier is no lost worker —
 * live, on a one-worker Foreground TCP server (`maxRequests: 1`: the first
 * connection recycles its worker) whose `ready()` is a barrier built like the
 * HTTP Auto-TLS one: turn after turn it heeds the workers (`heed()`) and
 * fails startup when one is lost (`audit()`).
 *
 * - Reaped first: the barrier sleeps through the recycle until the worker's
 *   exit wakes it, so the master reaps that exit before it reads the beacon —
 *   the recycle is still proved (`recycled, reforking`), never a death
 *   before startup completed.
 * - Read first: the barrier turns every 100 ms while the recycling worker
 *   still drains a connection, so the master reads the announcement and
 *   relieves the worker (`recycling, forking its replacement`) — a retiree,
 *   not a lost worker.
 *
 * Either way the barrier crosses, the worker is listed as recycled, and its
 * slot is reforked once startup completes: a new worker answers.
 */
return new Test(
   description: 'Recycling: a worker that recycles inside the startup barrier is not lost, and its slot is reforked after it',
   skip: function_exists('posix_kill') === false
      || function_exists('posix_setsid') === false
      || function_exists('proc_open') === false,
   test: new Assertions(Case: function (): Generator {
      $Script = <<<'PHP'
$Session = posix_setsid();
if ($Session === false || $Session === -1) {
   exit(125);
}

require getenv('BARRIER_AUTOBOOT');

use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Configs;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Events;

final class BarrierProbe extends TCP_Server_CLI
{
   public static function boot (mixed $Environment): void
   {
   }

   // # A startup barrier like the HTTP Auto-TLS one, of a fixed length
   protected function ready (): bool
   {
      $report = (string) getenv('BARRIER_REPORT');
      $Workers = $this->Process->Children->PIDs;
      file_put_contents("{$report}.open", (string) json_encode($Workers));

      $until = hrtime(true) + 4_000_000_000;
      $turn = (int) getenv('BARRIER_TURN');
      while (hrtime(true) < $until) {
         pcntl_signal_dispatch();
         $this->heed();
         $Lost = $this->audit($Workers);
         if ($Lost !== []) {
            file_put_contents($report, 'lost:' . json_encode($Lost));

            return false;
         }
         usleep($turn);
      }
      file_put_contents($report, 'crossed:' . json_encode($this->recycled));

      return true;
   }
}

$Server = new BarrierProbe(Modes::Foreground);
$Server->configure(new Configs(
   host: '127.0.0.1',
   port: (int) getenv('BARRIER_PORT'),
   workers: 1,
   maxRequests: 1,
   jitter: 0.0,
));
$Server->on(Events::DataReceive, static fn ($input): string => (string) getmypid());
$Server->start();
PHP;

      /**
       * Boot one server with a barrier of `$turn` microsecond turns; connect
       * once inside the barrier and hold that connection `$hold` seconds.
       *
       * @return array{0:array<string,mixed>,1:string,2:bool,3:int}
       */
      $Run = static function (int $turn, float $hold) use ($Script): array {
         // @ Reserve a free loopback port.
         $Reservation = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
         $name = is_resource($Reservation) ? (string) stream_socket_get_name($Reservation, false) : '';
         $port = (int) substr($name, (int) strrpos($name, ':') + 1);
         if (is_resource($Reservation)) {
            fclose($Reservation);
         }
         $report = BOOTGLY_STORAGE_DIR . "recycle-barrier-{$port}.report";
         @unlink($report);
         @unlink("{$report}.open");

         $Environment = (array) getenv();
         $Environment['BARRIER_AUTOBOOT'] = BOOTGLY_ROOT_DIR . 'autoboot.php';
         $Environment['BARRIER_PORT'] = (string) $port;
         $Environment['BARRIER_REPORT'] = $report;
         $Environment['BARRIER_TURN'] = (string) $turn;
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
         /** Wait until $file exists; its contents or null. */
         $Await = static function (string $file, float $seconds) use ($Read): null|string {
            $deadline = hrtime(true) + (int) ($seconds * 1_000_000_000);
            do {
               $Read();
               clearstatcache();
               if (is_file($file)) {
                  return (string) file_get_contents($file);
               }
               usleep(20_000);
            } while (hrtime(true) < $deadline);

            return null;
         };
         /** One exchange on a connection; the answering worker PID, or 0. */
         $Ask = static function ($Client): int {
            @fwrite($Client, 'x');
            $answer = '';
            $deadline = hrtime(true) + 3_000_000_000;
            while (hrtime(true) < $deadline) {
               $chunk = @fread($Client, 64);
               if ($chunk !== false && $chunk !== '') {
                  $answer .= $chunk;
                  break;
               }
               usleep(10_000);
            }

            return (int) $answer;
         };

         $Observed = [];
         $old = 0;
         $clean = false;
         try {
            $opened = $Await("{$report}.open", 8.0);
            // # Inside the barrier: the first connection recycles the worker
            $old = 0;
            $Client = $opened !== null
               ? @stream_socket_client("tcp://127.0.0.1:{$port}", $code, $message, 2.0)
               : false;
            if ($Client !== false) {
               stream_set_blocking($Client, false);
               $old = $Ask($Client);
               usleep((int) ($hold * 1_000_000));
               fclose($Client);
            }
            $verdict = $Await($report, 8.0);

            // # After the barrier: the slot is reforked and answers
            $deadline = hrtime(true) + 4_000_000_000;
            while (str_contains($Read(), 'recovered (new PID') === false && hrtime(true) < $deadline) {
               usleep(20_000);
            }
            $fresh = 0;
            $New = @stream_socket_client("tcp://127.0.0.1:{$port}", $code, $message, 2.0);
            if ($New !== false) {
               stream_set_blocking($New, false);
               $fresh = $Ask($New);
               fclose($New);
            }

            $Observed = [
               'the barrier opened' => $opened !== null,
               'a worker answered inside it' => $old > 0,
               'the barrier crossed with that worker recycled' => $verdict === 'crossed:' . json_encode([$old => 0]),
               'its slot is reforked after it, and answers' => $fresh > 0 && $fresh !== $old,
               'master alive' => is_resource($Process) && proc_get_status($Process)['running'],
            ];
            $settle = hrtime(true) + 500_000_000;
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
            @unlink($report);
            @unlink("{$report}.open");
            foreach ((array) @scandir(BOOTGLY_STORAGE_DIR . 'pids') as $file) {
               if (str_starts_with((string) $file, "BarrierProbe.{$port}.")) {
                  @unlink(BOOTGLY_STORAGE_DIR . "pids/{$file}");
               }
            }
         }

         return [$Observed, $output, $clean, $old];
      };

      // @ Reaped first: one long barrier turn, cut short by the worker's exit
      [$Observed, $output, $clean, $old] = $Run(3_000_000, 0.0);
      $Observed['logged as a recycle reaped before its beacon was read'] = $old > 0
         && str_contains($output, "(PID: {$old}) recycled, reforking");
      $Observed['never a death before startup completed'] = str_contains($output, 'died before startup completed') === false
         && str_contains($output, 'crashed') === false
         && str_contains($output, 'Uncaught') === false;
      $Observed['group clean'] = $clean;

      yield new Assertion(description: 'a recycle reaped inside the barrier, before its beacon was read, is no lost worker')
         ->expect($Observed, Op::Identical, [
            'the barrier opened' => true,
            'a worker answered inside it' => true,
            'the barrier crossed with that worker recycled' => true,
            'its slot is reforked after it, and answers' => true,
            'master alive' => true,
            'logged as a recycle reaped before its beacon was read' => true,
            'never a death before startup completed' => true,
            'group clean' => true,
         ])
         ->assert();

      // @ Read first: short barrier turns while the worker still drains
      [$Observed, $output, $clean, $old] = $Run(100_000, 1.2);
      $Observed['the announcement relieved the worker inside the barrier'] = $old > 0
         && str_contains($output, "(PID: {$old}) recycling, forking its replacement")
         && str_contains($output, "(PID: {$old}) recycled.");
      $Observed['never a death before startup completed'] = str_contains($output, 'died before startup completed') === false
         && str_contains($output, 'crashed') === false
         && str_contains($output, 'Uncaught') === false;
      $Observed['group clean'] = $clean;

      yield new Assertion(description: 'a recycle announced inside the barrier relieves its worker — a retiree, not a lost worker')
         ->expect($Observed, Op::Identical, [
            'the barrier opened' => true,
            'a worker answered inside it' => true,
            'the barrier crossed with that worker recycled' => true,
            'its slot is reforked after it, and answers' => true,
            'master alive' => true,
            'the announcement relieved the worker inside the barrier' => true,
            'never a death before startup completed' => true,
            'group clean' => true,
         ])
         ->assert();
   }),
);
