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
 * A worker whose boot always fails is reforked a few times and then after a
 * growing delay — never once per boot time — and the slot serves again as
 * soon as the boot succeeds. A worker that died after it entered its event
 * loop — however young, a crash-on-request stream included — is reforked at
 * once, so a request that kills workers never backs a slot off.
 */
return new Test(
   description: 'TCP-24: a slot that keeps dying at boot is reforked after a capped backoff, and recovers on its own',
   skip: function_exists('posix_kill') === false
      || function_exists('posix_setsid') === false
      || function_exists('proc_open') === false,
   test: new Assertions(Case: function (): Generator {
      $Script = <<<'PHP'
$Session = posix_setsid();
if ($Session === false || $Session === -1) {
   exit(125);
}

require getenv('TCP_BACKOFF_AUTOBOOT');

use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Configs;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Events;

final class BackoffProbe extends TCP_Server_CLI
{
   public static function boot (mixed $Environment): void
   {
   }

   public function instance ()
   {
      // @ One line per worker boot; an armed boot fails like a refused
      //   socket or a throwing application boot would
      $log = (string) getenv('TCP_BACKOFF_LOG');
      file_put_contents("{$log}.boots", hrtime(true) . "\n", FILE_APPEND);
      clearstatcache();
      if (is_file("{$log}.arm")) {
         exit(1);
      }

      return parent::instance();
   }

   protected function describe (): array
   {
      file_put_contents((string) getenv('TCP_BACKOFF_LOG') . '.saves', hrtime(true) . "\n", FILE_APPEND);

      return parent::describe();
   }
}

$Server = new BackoffProbe(Modes::Foreground);
$Server->configure(new Configs(
   host: '127.0.0.1',
   port: (int) getenv('TCP_BACKOFF_PORT'),
   workers: 1,
));
$Server->on(Events::DataReceive, static function ($input): string {
   if ($input === 'crash') {
      exit(3);
   }

   return (string) getmypid();
});
$Server->start();
PHP;

      // @ Reserve a free loopback port.
      $Reservation = stream_socket_server('tcp://127.0.0.1:0', $code, $message);
      $name = is_resource($Reservation) ? (string) stream_socket_get_name($Reservation, false) : '';
      $port = (int) substr($name, (int) strrpos($name, ':') + 1);
      if (is_resource($Reservation)) {
         fclose($Reservation);
      }
      $log = BOOTGLY_STORAGE_DIR . "tcp-revive-backoff-{$port}";
      foreach (['boots', 'saves', 'arm'] as $suffix) {
         @unlink("{$log}.{$suffix}");
      }
      /** hrtime stamps written to "{$log}.{$suffix}" at or after $since. */
      $Count = static function (string $suffix, int $since, int $until = PHP_INT_MAX) use ($log): int {
         $Lines = @file("{$log}.{$suffix}", FILE_IGNORE_NEW_LINES) ?: [];

         return count(array_filter($Lines, static fn (string $line): bool => (int) $line >= $since && (int) $line <= $until));
      };

      $Environment = (array) getenv();
      $Environment['TCP_BACKOFF_AUTOBOOT'] = BOOTGLY_ROOT_DIR . 'autoboot.php';
      $Environment['TCP_BACKOFF_PORT'] = (string) $port;
      $Environment['TCP_BACKOFF_LOG'] = $log;
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
      /** The PID of the worker answering within $seconds, or 0. */
      $Serving = static function (float $seconds) use ($port, $Drain): int {
         $deadline = hrtime(true) + (int) ($seconds * 1e9);
         do {
            $Drain();
            $Client = @stream_socket_client("tcp://127.0.0.1:{$port}", $code, $message, 0.2);
            if ($Client !== false) {
               stream_set_timeout($Client, 0, 200_000);
               fwrite($Client, 'pid');
               $PID = (int) fread($Client, 64);
               fclose($Client);
               if ($PID > 0) {
                  return $PID;
               }
            }
            usleep(50_000);
         } while (hrtime(true) < $deadline);

         return 0;
      };
      /** Send one crashing request. */
      $Crash = static function () use ($port): void {
         $Client = @stream_socket_client("tcp://127.0.0.1:{$port}", $code, $message, 0.2);
         if ($Client !== false) {
            @fwrite($Client, 'crash');
            fclose($Client);
         }
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
         $original = $Serving(6.0);
         $Observed['initial worker serves'] = $original > 0;

         // @ Every boot fails from now on: lose the serving worker
         touch("{$log}.arm");
         $armed = (int) hrtime(true);
         if ($original > 0) {
            posix_kill($original, SIGKILL);
         }
         $Idle(3.0);
         $window = (int) hrtime(true);
         $boots = $Count('boots', $armed, $window);
         $saves = $Count('saves', $armed, $window);
         $Observed['still reforked (>= 3 boots in 3 s)'] = $boots >= 3;
         $Observed['bounded (<= 6 boots in 3 s)'] = $boots <= 6;
         $Observed['state saves bounded (<= 6 in 3 s)'] = $saves <= 6;
         $Observed['crash loop logged'] = str_contains($output, 'times in a row');
         // @ The schedule itself: two immediate retries, then 0.5 s, then 1 s
         $Times = array_values(array_map('intval', array_filter(
            @file("{$log}.boots", FILE_IGNORE_NEW_LINES) ?: [],
            static fn (string $line): bool => (int) $line >= $armed && (int) $line <= $window
         )));
         $Gap = static fn (int $from, int $to): int => isSet($Times[$to], $Times[$from])
            ? intdiv($Times[$to] - $Times[$from], 1_000_000)
            : -1;
         $Observed['two immediate retries (3 boots within 300 ms)'] = $Gap(0, 2) >= 0 && $Gap(0, 2) < 300;
         $Observed['then 0.5 s (450-900 ms)'] = $Gap(2, 3) >= 450 && $Gap(2, 3) < 900;
         $Observed['then doubled to 1 s (950-1600 ms)'] = $Gap(3, 4) >= 950 && $Gap(3, 4) < 1_600;
         $Observed['master alive while backing off'] = $Alive();

         // @ The boot succeeds again: the slot serves on its next refork
         @unlink("{$log}.arm");
         $recovered = $Serving(6.5);
         $Observed['slot serves again once its boot succeeds'] = $recovered > 0 && $recovered !== $original;

         // @ A worker that served is reforked at once: its death resets the streak
         if ($recovered > 0) {
            posix_kill($recovered, SIGKILL);
         }
         $replaced = $Serving(1.5);
         $Observed['served worker reforked at once'] = $replaced > 0 && $replaced !== $recovered;

         // @ A worker killed by a request had served: however young, it is
         //   reforked at once — a crash-on-request stream never backs a slot off
         $logged = substr_count($output, 'times in a row');
         $slowest = 0.0;
         $serving = $replaced;
         for ($kill = 0; $kill < 5 && $serving > 0; $kill++) {
            $Crash();
            $started = hrtime(true);
            do {
               $next = $Serving(0.2);
            } while (($next === 0 || $next === $serving) && hrtime(true) - $started < 3_000_000_000);
            $slowest = max($slowest, (hrtime(true) - $started) / 1e9);
            $serving = $next !== $serving ? $next : 0;
         }
         $Observed['served workers killed 5 times in a row reforked at once'] = $serving > 0 && $slowest < 1.0;
         $Observed['no backoff for served workers'] = substr_count($output, 'times in a row') === $logged;
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
         $cleanup = hrtime(true) + 500_000_000;
         while ($master > 0 && posix_kill(-$master, 0) && hrtime(true) < $cleanup) {
            usleep(10_000);
         }
         $Observed['group clean'] = $master > 0 && posix_kill(-$master, 0) === false;
         foreach (['boots', 'saves', 'arm'] as $suffix) {
            @unlink("{$log}.{$suffix}");
         }
         foreach ((array) @scandir(BOOTGLY_STORAGE_DIR . 'pids') as $file) {
            if (str_starts_with((string) $file, "BackoffProbe.{$port}.")) {
               @unlink(BOOTGLY_STORAGE_DIR . "pids/{$file}");
            }
         }
      }

      yield new Assertion(description: 'a boot crash loop is backed off, logged, and recovers on its own')
         ->expect($Observed, Op::Identical, [
            'initial worker serves' => true,
            'still reforked (>= 3 boots in 3 s)' => true,
            'bounded (<= 6 boots in 3 s)' => true,
            'state saves bounded (<= 6 in 3 s)' => true,
            'crash loop logged' => true,
            'two immediate retries (3 boots within 300 ms)' => true,
            'then 0.5 s (450-900 ms)' => true,
            'then doubled to 1 s (950-1600 ms)' => true,
            'master alive while backing off' => true,
            'slot serves again once its boot succeeds' => true,
            'served worker reforked at once' => true,
            'served workers killed 5 times in a row reforked at once' => true,
            'no backoff for served workers' => true,
            'master alive' => true,
            'group clean' => true,
         ])
         ->assert();
   }),
);
