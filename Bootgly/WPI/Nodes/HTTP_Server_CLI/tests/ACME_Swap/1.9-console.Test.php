<?php

use Bootgly\ACI\Tests\Suite\Test;

return new Test(
   description: 'ACME(lifecycle): the Interactive console survives a prompt cycle + signal, `status` after a refork, and a typed `stop`',
   test: function () {
      $storage = sys_get_temp_dir() . '/bootgly-console-' . getmypid();
      $counter = "{$storage}/ticks";
      $port = 18114;
      $state = "{$storage}/pids/ConsoleServer.{$port}.json";
      $Process = null;
      $Pipes = [];
      $master = 0;
      $PIDs = [];
      $reforked = false;
      $survived = false;
      $reported = false;
      $stopped = false;
      $clean = false;

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
      $Alive = static function (int $PID): bool {
         $status = @file_get_contents("/proc/{$PID}/status");

         return is_string($status) && preg_match('/^State:\s+Z/m', $status) !== 1;
      };
      $Ticks = static fn (): int => (int) @file_get_contents($counter);
      $Advance = static function (int $count = 2) use ($Wait, $Ticks): bool {
         $baseline = $Ticks();

         return $Wait(static fn (): bool => $Ticks() >= $baseline + $count);
      };

      mkdir($storage, 0700, true);
      putenv('BOOTGLY_CONSOLE_ROOT=' . BOOTGLY_ROOT_BASE);
      putenv("BOOTGLY_CONSOLE_STORAGE={$storage}");
      putenv("BOOTGLY_CONSOLE_COUNTER={$counter}");
      putenv("BOOTGLY_CONSOLE_PORT={$port}");

      try {
         // ! The console only exists on a terminal: with a plain pipe the
         //   readline callback API never fires, so typed commands need a pty.
         try {
            $Process = proc_open(
               [PHP_BINARY, __DIR__ . '/console.php'],
               [['pty'], ['pty'], ['pty']],
               $Pipes,
               BOOTGLY_ROOT_BASE
            );
         }
         catch (ValueError) {
            // ? No pty support in this PHP build — nothing here is testable.
            yield assert(
               assertion: true,
               description: 'SKIPPED: proc_open has no pty support on this system — typed-console paths not exercised'
            );

            return;
         }

         if (is_resource($Process)) {
            stream_set_blocking($Pipes[1], false);
            $Drain = static function () use ($Pipes): void {
               for ($i = 0; $i < 4; $i++) {
                  $chunk = @fread($Pipes[1], 65536);
                  if ($chunk === false || $chunk === '') {
                     break;
                  }
               }
            };
            $Type = static function (string $line, float $settle = 1.5) use ($Pipes, $Drain): void {
               @fwrite($Pipes[0], $line);
               $deadline = microtime(true) + $settle;
               while (microtime(true) < $deadline) {
                  $Drain();
                  usleep(50000);
               }
            };

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

               return $master > 0 && count($PIDs) === 2;
            });
            $Advance();

            // @ One full prompt cycle (accept-line + handler remove/reinstall),
            //   then an external signal. Accepting a line leaves the sigaction
            //   slots libreadline restored pointing at SIG_ERR (-1): without
            //   the re-arm in `disarm()`, this delivery makes the kernel jump
            //   to -1 and the master dies with SIGSEGV inside `stream_select`.
            $Type("help\n");
            posix_kill($master, SIGCONT);
            $survived = $Advance();

            // @ TCP-4 precondition: crash a worker so `revive()` re-pushes its
            //   index and `Children->PIDs` iterates out of order ([1, 0]).
            posix_kill($PIDs[0], SIGKILL);
            $reforked = $Wait(static function () use ($state, $PIDs): bool {
               $JSON = @file_get_contents($state);
               $topology = is_string($JSON) ? json_decode($JSON, true) : null;
               $workers = is_array($topology['workers'] ?? null)
                  ? array_values(array_filter($topology['workers'], 'is_int'))
                  : [];

               return count($workers) === 2 && in_array($PIDs[0], $workers, true) === false;
            });

            // @ TCP-4: a typed `status` keyed the render objects by the PIDs
            //   array KEY — after the refork, `Undefined array key` escalated
            //   into a fatal that took the whole server down.
            $Type("status\n", 2.0);
            $reported = $Advance();

            // @ A typed `stop` must exit 0 through stop() — never by signal.
            $Type("stop\n", 2.5);
            $stopped = $Wait(static function () use ($Process): bool {
               $status = proc_get_status($Process);

               return ($status['running'] ?? true) === false;
            });
            $status = proc_get_status($Process);
            $clean = $stopped
               && ($status['signaled'] ?? true) === false
               && ($status['exitcode'] ?? -1) === 0;

            // ! Refresh the surviving PID set for the teardown sweep.
            $JSON = @file_get_contents($state);
            $topology = is_string($JSON) ? json_decode($JSON, true) : null;
            $PIDs = array_unique([
               ...$PIDs,
               ...(is_array($topology['workers'] ?? null)
                  ? array_values(array_filter($topology['workers'], 'is_int'))
                  : [])
            ]);
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

         putenv('BOOTGLY_CONSOLE_ROOT');
         putenv('BOOTGLY_CONSOLE_STORAGE');
         putenv('BOOTGLY_CONSOLE_COUNTER');
         putenv('BOOTGLY_CONSOLE_PORT');

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
         assertion: $survived,
         description: 'a signal delivered after a prompt cycle finds re-armed handlers — the master survives'
      );
      yield assert(
         assertion: $reforked,
         description: 'a SIGKILLed worker is reforked while the console is live'
      );
      yield assert(
         assertion: $reported,
         description: 'a typed `status` after the refork leaves the master alive (no fatal on the reordered PID map)'
      );
      yield assert(
         assertion: $clean,
         description: 'a typed `stop` exits 0 through stop() — not by signal'
      );
   }
);
