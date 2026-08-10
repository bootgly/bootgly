<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;

return new Specification(
   description: 'ACME(lifecycle): Paused is not terminal — SIGTSTP keeps the master supervising and SIGCONT resumes it',
   test: function () {
      $storage = sys_get_temp_dir() . '/bootgly-pause-' . getmypid();
      $counter = "{$storage}/ticks";
      $journal = "{$storage}/journal";
      $port = 18113;
      $state = "{$storage}/pids/PausableServer.{$port}.json";
      $Process = null;
      $Pipes = [];
      $master = 0;
      $PIDs = [];
      $paused = false;
      $advertised = false;
      $ticking = false;
      $resumed = false;
      $readvertised = false;
      $stopped = false;

      $Wait = static function (Closure $Condition, float $seconds = 6.0): bool {
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
      putenv('BOOTGLY_PAUSE_ROOT=' . BOOTGLY_ROOT_BASE);
      putenv("BOOTGLY_PAUSE_STORAGE={$storage}");
      putenv("BOOTGLY_PAUSE_COUNTER={$counter}");
      putenv("BOOTGLY_PAUSE_JOURNAL={$journal}");
      putenv("BOOTGLY_PAUSE_PORT={$port}");

      try {
         $Process = proc_open(
            [PHP_BINARY, __DIR__ . '/pause.php'],
            [
               ['pipe', 'r'],
               ['file', '/dev/null', 'a'],
               ['file', '/dev/null', 'a']
            ],
            $Pipes,
            BOOTGLY_ROOT_BASE
         );
         if (is_resource($Process)) {
            // ! Booted: topology published and the supervision tick running.
            $Wait(static function () use ($state, &$master, &$PIDs): bool {
               $JSON = @file_get_contents($state);
               $topology = is_string($JSON) ? json_decode($JSON, true) : null;
               if (is_array($topology) === false) {
                  return false;
               }
               $master = (int) ($topology['master'] ?? 0);
               $PIDs = is_array($topology['workers'] ?? null)
                  ? array_values(array_filter($topology['workers'], 'is_int'))
                  : [];

               return $master > 0 && $PIDs !== [];
            });
            $Wait(static fn (): bool => (int) @file_get_contents($counter) >= 2);

            // @ SIGTSTP — the master must acknowledge the pause...
            posix_kill($master, SIGTSTP);
            $paused = $Wait(
               static fn (): bool => str_contains((string) @file_get_contents($journal), 'pause')
            );

            // ...and KEEP running its loop: dispatching signals and ticking.
            //   This is the TCP-1 regression — a terminal `Paused` ends the
            //   loop, `start()` returns and the ticks freeze here.
            $baseline = (int) @file_get_contents($counter);
            $ticking = $Wait(
               static fn (): bool => (int) @file_get_contents($counter) >= $baseline + 2
            );

            // @ The pause must be visible out-of-process: pause() republishes
            //   the state document with the master's Status name, which is
            //   what `project show` renders as `paused`.
            $advertised = $Wait(static function () use ($state): bool {
               $JSON = @file_get_contents($state);
               $topology = is_string($JSON) ? json_decode($JSON, true) : null;

               return ($topology['status'] ?? '') === 'Paused';
            });

            // @ SIGCONT — only a live loop can dispatch it into `resume()`.
            posix_kill($master, SIGCONT);
            $resumed = $Wait(
               static fn (): bool => str_contains((string) @file_get_contents($journal), 'resume')
            );

            // @ ...and the state document must advertise the resume too.
            $readvertised = $Wait(static function () use ($state): bool {
               $JSON = @file_get_contents($state);
               $topology = is_string($JSON) ? json_decode($JSON, true) : null;

               return ($topology['status'] ?? '') === 'Running';
            });

            // @ SIGTERM — an orderly stop() teardown must still follow.
            fclose($Pipes[0]);
            unset($Pipes[0]);
            proc_terminate($Process, SIGTERM);
            $stopped = $Wait(static function () use ($Process, $Alive, $PIDs, $state): bool {
               $status = proc_get_status($Process);
               if (($status['running'] ?? true) !== false || ($status['signaled'] ?? false) === true) {
                  return false;
               }
               // ? `State::clean()` tombstones the pid file — content is stale.
               $JSON = @file_get_contents($state);
               $topology = is_string($JSON) ? json_decode($JSON, true) : null;
               if (is_array($topology)) {
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

         putenv('BOOTGLY_PAUSE_ROOT');
         putenv('BOOTGLY_PAUSE_STORAGE');
         putenv('BOOTGLY_PAUSE_COUNTER');
         putenv('BOOTGLY_PAUSE_JOURNAL');
         putenv('BOOTGLY_PAUSE_PORT');

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
         assertion: $paused,
         description: 'SIGTSTP drives the Interactive master through pause()'
      );
      yield assert(
         assertion: $ticking,
         description: 'the paused master keeps dispatching signals and ticking — Paused is not terminal'
      );
      yield assert(
         assertion: $advertised,
         description: 'the paused master republishes its state document with status Paused'
      );
      yield assert(
         assertion: $resumed,
         description: 'SIGCONT reaches resume() — the paused master is still a control channel'
      );
      yield assert(
         assertion: $readvertised,
         description: 'the resumed master republishes its state document with status Running'
      );
      yield assert(
         assertion: $stopped,
         description: 'SIGTERM still tears the paused/resumed server down through stop()'
      );
   }
);
