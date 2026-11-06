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
 * A worker the master could not give a beacon never recycles — live, on a
 * one-worker Foreground TCP server (`maxRequests: 2`, `jitter: 0`) whose
 * master runs out of descriptors for one supervision turn: the crashed
 * worker's refork is forked without its beacon pair. It could neither
 * announce a recycle — no replacement would serve its slot while it drained
 * — nor hear the master's go, so it says it will not recycle and serves on
 * past its limit: no drain, no exit taken for a crash.
 */
return new Test(
   description: 'Recycling: a worker forked without its beacon pair never recycles, and says so',
   skip: function_exists('posix_kill') === false
      || function_exists('posix_setsid') === false
      || function_exists('posix_setrlimit') === false
      || function_exists('proc_open') === false
      || is_dir('/proc/self/fd') === false,
   test: new Assertions(Case: function (): Generator {
      $Script = <<<'PHP'
$Session = posix_setsid();
if ($Session === false || $Session === -1) {
   exit(125);
}

require getenv('BEACONLESS_AUTOBOOT');

use Bootgly\ABI\Events\Emitter;
use Bootgly\ACI\Process\Events as Worker;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Configs;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Events;

/** The hard descriptor limit; a soft one a process may raise up to it. */
function ceiling (): int
{
   $hard = posix_getrlimit()['hard openfiles'] ?? 'unlimited';

   return $hard === 'unlimited' ? 65_536 : (int) $hard;
}

// ! Every worker takes its descriptors back before it serves
Emitter::$Instance->listen(Worker::Boot, static function (): void {
   posix_setrlimit(POSIX_RLIMIT_NOFILE, ceiling(), ceiling());
});

final class BeaconlessProbe extends TCP_Server_CLI
{
   public static function boot (mixed $Environment): void
   {
   }

   // # While the flag file exists, each supervision turn runs out of
   //   descriptors: a beacon pair needs two, one at most is free
   protected function tick (): void
   {
      clearstatcache();
      $starve = is_file((string) getenv('BEACONLESS_FLAG'));
      $soft = posix_getrlimit()['soft openfiles'] ?? 'unlimited';
      $soft = $soft === 'unlimited' ? ceiling() : (int) $soft;
      $Plug = false;
      if ($starve) {
         // ! The limit bounds the descriptor NUMBER: just above the lowest
         //   free one — which the listing holds as scandir()'s own — and
         //   that one plugged, so the dead worker's beacon end this turn
         //   closes is the only descriptor left
         $Open = array_flip(array_map('intval', array_diff((array) scandir('/proc/self/fd'), ['.', '..'])));
         $free = 0;
         while (isSet($Open[$free])) {
            $free++;
         }
         posix_setrlimit(POSIX_RLIMIT_NOFILE, $free, ceiling());
         $Plug = @fopen('/dev/null', 'r');
      }
      try {
         parent::tick();
      }
      finally {
         if (is_resource($Plug)) {
            fclose($Plug);
         }
         if ($starve) {
            posix_setrlimit(POSIX_RLIMIT_NOFILE, $soft, ceiling());
         }
      }
   }
}

$Server = new BeaconlessProbe(Modes::Foreground);
$Server->configure(new Configs(
   host: '127.0.0.1',
   port: (int) getenv('BEACONLESS_PORT'),
   workers: 1,
   maxRequests: 2,
   jitter: 0.0,
));
$Server->on(Events::DataReceive, static fn ($input): string => (string) getmypid());
$Server->start();
PHP;

      // @ Reserve a free loopback port.
      $Reservation = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
      $name = is_resource($Reservation) ? (string) stream_socket_get_name($Reservation, false) : '';
      $port = (int) substr($name, (int) strrpos($name, ':') + 1);
      if (is_resource($Reservation)) {
         fclose($Reservation);
      }
      $flag = BOOTGLY_STORAGE_DIR . "recycle-beaconless-{$port}.flag";
      @unlink($flag);

      $Environment = (array) getenv();
      $Environment['BEACONLESS_AUTOBOOT'] = BOOTGLY_ROOT_DIR . 'autoboot.php';
      $Environment['BEACONLESS_PORT'] = (string) $port;
      $Environment['BEACONLESS_FLAG'] = $flag;
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
      /** One connection; the answering worker PID or 0. */
      $Ask = static function () use ($port): int {
         $Client = @stream_socket_client("tcp://127.0.0.1:{$port}", $code, $message, 2.0);
         if ($Client === false) {
            return 0;
         }
         stream_set_timeout($Client, 4);
         @fwrite($Client, 'pid');
         $PID = (int) fread($Client, 64);
         fclose($Client);

         return $PID;
      };
      /** Wait until the log says $line; whether it did. */
      $Expect = static function (string $line, float $seconds) use ($Read): bool {
         $deadline = hrtime(true) + (int) ($seconds * 1_000_000_000);
         do {
            if (str_contains($Read(), $line)) {
               return true;
            }
            usleep(20_000);
         } while (hrtime(true) < $deadline);

         return false;
      };

      $Observed = [];
      $clean = false;
      try {
         $up = $Expect('Running in Foreground mode', 8.0);
         $first = $up ? $Ask() : 0;

         // # The worker crashes while the master is out of descriptors
         touch($flag);
         if ($first > 0) {
            posix_kill($first, SIGKILL);
         }
         $reforked = $Expect('recovered (new PID: ', 4.0);
         @unlink($flag);
         $refork = preg_match('/recovered \(new PID: (\d+)\)/', $Read(), $Match) === 1 ? (int) $Match[1] : 0;
         $said = $Expect("Worker #0 has no beacon to announce a recycle (the master could not create its pair): it will not recycle.", 3.0);

         // # Past its limit of 2: it serves on, never recycling
         $PIDs = [];
         for ($connection = 0; $refork > 0 && $connection < 6; $connection++) {
            $PIDs[] = $Ask();
         }
         $settle = hrtime(true) + 1_200_000_000;
         while (hrtime(true) < $settle) {
            $Read();
            usleep(50_000);
         }

         $Observed = [
            'server up, a worker serves' => $up && $first > 0,
            'the crash is reforked' => $reforked && $refork > 0 && $refork !== $first,
            'the refork says it will not recycle' => $said,
            'it serves past its limit (6 of 6)' => $PIDs === array_fill(0, 6, $refork),
            'master alive' => is_resource($Process) && proc_get_status($Process)['running'],
         ];
      }
      finally {
         @unlink($flag);
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
            if (str_starts_with((string) $file, "BeaconlessProbe.{$port}.")) {
               @unlink(BOOTGLY_STORAGE_DIR . "pids/{$file}");
            }
         }
      }
      $Observed['no recycle'] = str_contains($output, 'recycling') === false;
      $Observed['only the induced crash'] = substr_count($output, 'crashed, reforking') === 1
         && str_contains($output, 'Uncaught') === false;
      $Observed['group clean'] = $clean;

      yield new Assertion(description: 'a worker forked without its beacon pair never recycles, and says so')
         ->expect($Observed, Op::Identical, [
            'server up, a worker serves' => true,
            'the crash is reforked' => true,
            'the refork says it will not recycle' => true,
            'it serves past its limit (6 of 6)' => true,
            'master alive' => true,
            'no recycle' => true,
            'only the induced crash' => true,
            'group clean' => true,
         ])
         ->assert();
   }),
);
