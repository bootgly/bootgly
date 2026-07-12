<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;

return new Specification(
   description: 'ACME(lifecycle): Interactive mode keeps supervising while input is idle',
   test: function () {
      $storage = sys_get_temp_dir() . '/bootgly-interactive-tick-' . getmypid();
      $counter = "{$storage}/ticks";
      $port = 18110;
      $state = "{$storage}/pids/InteractiveServer.{$port}.json";
      $Process = null;
      $Pipes = [];
      $PIDs = [];
      $ticked = false;
      $stopped = false;

      $Wait = static function (Closure $Condition, float $seconds = 5.0): bool {
         $deadline = microtime(true) + $seconds;
         do {
            if ($Condition()) {
               return true;
            }
            usleep(50000);
         } while (microtime(true) < $deadline);

         return false;
      };
      $Alive = static function (int $PID): bool {
         $status = @file_get_contents("/proc/{$PID}/status");

         return is_string($status) && preg_match('/^State:\s+Z/m', $status) !== 1;
      };

      mkdir($storage, 0700, true);
      putenv('BOOTGLY_INTERACTIVE_ROOT=' . BOOTGLY_ROOT_BASE);
      putenv("BOOTGLY_INTERACTIVE_STORAGE={$storage}");
      putenv("BOOTGLY_INTERACTIVE_COUNTER={$counter}");
      putenv("BOOTGLY_INTERACTIVE_PORT={$port}");

      try {
         $Process = proc_open(
            [PHP_BINARY, __DIR__ . '/interactive.php'],
            [
               ['pipe', 'r'],
               ['file', '/dev/null', 'a'],
               ['file', '/dev/null', 'a']
            ],
            $Pipes,
            BOOTGLY_ROOT_BASE
         );
         if (is_resource($Process)) {
            // No input is written before this check: tick() must continue while
            // readline is idle rather than blocking the master indefinitely.
            $ticked = $Wait(
               static fn (): bool => (int) @file_get_contents($counter) >= 3
            );
            $JSON = @file_get_contents($state);
            $topology = is_string($JSON) ? json_decode($JSON, true) : null;
            $PIDs = is_array($topology['workers'] ?? null)
               ? array_values(array_filter($topology['workers'], 'is_int'))
               : [];
            fclose($Pipes[0]);
            unset($Pipes[0]);
            proc_terminate($Process, SIGTERM);
            $stopped = $Wait(static function () use ($Process, $Alive, $PIDs, $state): bool {
               $status = proc_get_status($Process);
               if (($status['running'] ?? true) !== false || is_file($state)) {
                  return false;
               }
               foreach ($PIDs as $PID) {
                  if ($Alive($PID)) {
                     return false;
                  }
               }

               return true;
            });
         }
      }
      finally {
         foreach ($Pipes as $Pipe) {
            is_resource($Pipe) && fclose($Pipe);
         }
         if (is_resource($Process)) {
            if ($stopped === false) {
               proc_terminate($Process, SIGKILL);
            }
            proc_close($Process);
         }
         foreach ($PIDs as $PID) {
            if ($Alive($PID)) {
               posix_kill($PID, SIGKILL);
            }
         }

         putenv('BOOTGLY_INTERACTIVE_ROOT');
         putenv('BOOTGLY_INTERACTIVE_STORAGE');
         putenv('BOOTGLY_INTERACTIVE_COUNTER');
         putenv('BOOTGLY_INTERACTIVE_PORT');

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
         assertion: $ticked,
         description: 'Interactive mode calls the supervision tick repeatedly before any input arrives'
      );
      yield assert(
         assertion: $stopped,
         description: 'the idle Interactive loop still dispatches a termination signal promptly'
      );
   }
);
