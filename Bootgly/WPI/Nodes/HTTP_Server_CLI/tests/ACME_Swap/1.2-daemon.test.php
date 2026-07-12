<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;

return new Specification(
   description: 'ACME(lifecycle): the default daemon master owns, reaps and reforks its workers',
   test: function () {
      $storage = sys_get_temp_dir() . '/bootgly-daemon-topology-' . getmypid();
      $port = 18109;
      $state = "{$storage}/pids/HTTP_Server_CLI.{$port}.json";
      $master = 0;
      $PIDs = [];
      $replacement = 0;
      $launcher = null;
      $started = false;
      $owned = false;
      $recovered = false;
      $stopped = false;
      $failed = false;

      $Read = static function () use ($state): null|array {
         $JSON = @file_get_contents($state);
         $decoded = is_string($JSON) ? json_decode($JSON, true) : null;

         return is_array($decoded) ? $decoded : null;
      };
      $Parent = static function (int $PID): null|int {
         $status = @file_get_contents("/proc/{$PID}/status");
         if (
            is_string($status)
            && preg_match('/^State:\s+Z/m', $status) !== 1
            && preg_match('/^PPid:\t(\d+)$/m', $status, $matches) === 1
         ) {
            return (int) $matches[1];
         }

         return null;
      };
      $Alive = static function (int $PID): bool {
         $status = @file_get_contents("/proc/{$PID}/status");

         return is_string($status) && preg_match('/^State:\s+Z/m', $status) !== 1;
      };
      $Wait = static function (Closure $Condition, float $seconds = 8.0): bool {
         $deadline = microtime(true) + $seconds;
         do {
            if ($Condition()) {
               return true;
            }
            usleep(50000);
         } while (microtime(true) < $deadline);

         return false;
      };

      putenv('BOOTGLY_DAEMON_ROOT=' . BOOTGLY_ROOT_BASE);
      putenv("BOOTGLY_DAEMON_STORAGE={$storage}");
      putenv("BOOTGLY_DAEMON_PORT={$port}");

      try {
         putenv('BOOTGLY_DAEMON_FAIL=1');
         $Failure = proc_open(
            [PHP_BINARY, __DIR__ . '/daemon.php'],
            [
               ['file', '/dev/null', 'r'],
               ['file', '/dev/null', 'a'],
               ['file', '/dev/null', 'a']
            ],
            $FailurePipes,
            BOOTGLY_ROOT_BASE
         );
         $failed = is_resource($Failure) && proc_close($Failure) !== 0;
         putenv('BOOTGLY_DAEMON_FAIL');

         $Launcher = proc_open(
            [PHP_BINARY, __DIR__ . '/daemon.php'],
            [
               ['file', '/dev/null', 'r'],
               ['file', '/dev/null', 'a'],
               ['file', '/dev/null', 'a']
            ],
            $Pipes,
            BOOTGLY_ROOT_BASE
         );
         $launcher = is_resource($Launcher) ? $Launcher : null;
         if ($launcher !== null) {
            $started = proc_close($Launcher) === 0 && $Wait(
               static fn (): bool => is_file($state)
            );
            $launcher = null;
         }

         $topology = $Read();
         $master = is_int($topology['master'] ?? null) ? $topology['master'] : 0;
         $PIDs = is_array($topology['workers'] ?? null)
            ? array_values(array_filter($topology['workers'], 'is_int'))
            : [];
         $owned = $started && $master > 1 && count($PIDs) === 2;
         foreach ($PIDs as $PID) {
            $owned = $owned && $Parent($PID) === $master;
         }

         if ($owned) {
            $dead = $PIDs[0];
            posix_kill($dead, SIGKILL);
            $recovered = $Wait(function () use ($Read, $Parent, $master, $dead, $PIDs, &$replacement): bool {
               $current = $Read();
               $workers = is_array($current['workers'] ?? null)
                  ? array_values(array_filter($current['workers'], 'is_int'))
                  : [];
               foreach ($workers as $PID) {
                  if ($PID !== $dead && in_array($PID, $PIDs, true) === false) {
                     $replacement = $PID;
                  }
               }

               return count($workers) === 2
                  && in_array($dead, $workers, true) === false
                  && $replacement > 1
                  && $Parent($replacement) === $master;
            });
         }
      }
      finally {
         if (is_resource($launcher)) {
            proc_terminate($launcher, SIGKILL);
            proc_close($launcher);
         }
         if ($master > 1 && $Alive($master)) {
            posix_kill($master, SIGTERM);
         }
         $stopped = $master < 2 || $Wait(
            static function () use ($Alive, $master, $PIDs, &$replacement, $state): bool {
               if ($Alive($master) || is_file($state)) {
                  return false;
               }
               foreach (array_unique([...$PIDs, $replacement]) as $PID) {
                  if ($PID > 1 && $Alive($PID)) {
                     return false;
                  }
               }

               return true;
            },
            12.0
         );

         // Last-resort cleanup protects the suite if a regression leaves the
         // daemon alive; this does not influence the assertion above.
         if ($master > 1 && $Alive($master)) {
            posix_kill($master, SIGKILL);
         }
         foreach (array_unique([...$PIDs, $replacement]) as $PID) {
            if ($PID > 1 && $Alive($PID)) {
               posix_kill($PID, SIGKILL);
            }
         }

         putenv('BOOTGLY_DAEMON_ROOT');
         putenv('BOOTGLY_DAEMON_STORAGE');
         putenv('BOOTGLY_DAEMON_PORT');
         putenv('BOOTGLY_DAEMON_FAIL');

         if (is_dir($storage)) {
            $Iterator = new RecursiveIteratorIterator(
               new RecursiveDirectoryIterator($storage, FilesystemIterator::SKIP_DOTS),
               RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($Iterator as $Entry) {
               $Entry->isDir()
                  ? @rmdir($Entry->getPathname())
                  : @unlink($Entry->getPathname());
            }
            @rmdir($storage);
         }
      }

      yield assert(
         assertion: $failed,
         description: 'the launcher returns failure when the daemon never acknowledges complete startup'
      );
      yield assert(
         assertion: $started && $owned,
         description: 'the final daemon master is the real parent of both initial workers'
      );
      yield assert(
         assertion: $recovered,
         description: 'the daemon master reaps and reforks a crashed worker under itself'
      );
      yield assert(
         assertion: $stopped,
         description: 'SIGTERM drains the owned worker set and removes process state'
      );
   }
);
