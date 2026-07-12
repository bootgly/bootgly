<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;

return new Specification(
   description: 'ACME(lifecycle): workers stop when the daemon master dies abruptly',
   test: function () {
      $storage = sys_get_temp_dir() . '/bootgly-daemon-orphan-' . getmypid();
      $port = 18110;
      $state = "{$storage}/pids/HTTP_Server_CLI.{$port}.json";
      $master = 0;
      $PIDs = [];

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
         $started = is_resource($Launcher) && proc_close($Launcher) === 0;
         $topology = $started
            ? json_decode((string) @file_get_contents($state), true)
            : null;
         $master = is_array($topology) && is_int($topology['master'] ?? null)
            ? $topology['master']
            : 0;
         $PIDs = is_array($topology['workers'] ?? null)
            ? array_values(array_filter($topology['workers'], 'is_int'))
            : [];

         if ($master > 1) {
            posix_kill($master, SIGKILL);
         }
         $stopped = $master > 1 && count($PIDs) === 2 && $Wait(
            static function () use ($Alive, $master, $PIDs): bool {
               if ($Alive($master)) {
                  return false;
               }
               foreach ($PIDs as $PID) {
                  if ($Alive($PID)) {
                     return false;
                  }
               }

               return true;
            }
         );

         yield assert(
            assertion: $started && $stopped,
            description: 'workers detect reparenting and exit after an abrupt master SIGKILL'
         );
      }
      finally {
         if ($master > 1 && $Alive($master)) {
            posix_kill($master, SIGKILL);
         }
         foreach ($PIDs as $PID) {
            if ($Alive($PID)) {
               posix_kill($PID, SIGKILL);
            }
         }

         putenv('BOOTGLY_DAEMON_ROOT');
         putenv('BOOTGLY_DAEMON_STORAGE');
         putenv('BOOTGLY_DAEMON_PORT');

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
   }
);
