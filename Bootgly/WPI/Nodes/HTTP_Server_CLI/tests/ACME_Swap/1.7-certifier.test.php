<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;

return new Specification(
   description: 'ACME(lifecycle): a stalled certifier releases renew.lock after abrupt master death',
   test: function () {
      $storage = sys_get_temp_dir() . '/bootgly-autotls-certifier-' . getmypid();
      $port = 18112;
      $gate = 18080;
      $CA = 18443;
      $state = "{$storage}/pids/HTTP_Server_CLI.{$port}.json";
      $lock = "{$storage}/autotls/renew.lock";
      $master = 0;
      $PIDs = [];
      $blackhole = 0;

      $Alive = static function (int $PID): bool {
         $status = @file_get_contents("/proc/{$PID}/status");

         return is_string($status) && preg_match('/^State:\s+Z/m', $status) !== 1;
      };
      $Wait = static function (Closure $Condition, float $seconds = 10.0): bool {
         $deadline = microtime(true) + $seconds;
         do {
            if ($Condition()) {
               return true;
            }
            usleep(50000);
         } while (microtime(true) < $deadline);

         return false;
      };
      $Held = static function () use ($lock): bool {
         $Lock = @fopen($lock, 'c+');
         if ($Lock === false) {
            return false;
         }
         $acquired = flock($Lock, LOCK_EX | LOCK_NB);
         $acquired && flock($Lock, LOCK_UN);
         fclose($Lock);

         return $acquired === false;
      };
      $Children = static function (int $master): array {
         $PIDs = [];
         foreach (glob('/proc/[0-9]*/status') ?: [] as $statusFile) {
            $status = @file_get_contents($statusFile);
            if (
               is_string($status)
               && preg_match('/^Pid:\t(\d+)$/m', $status, $PIDMatch) === 1
               && preg_match('/^PPid:\t(\d+)$/m', $status, $parentMatch) === 1
               && (int) $parentMatch[1] === $master
            ) {
               $PIDs[] = (int) $PIDMatch[1];
            }
         }

         return $PIDs;
      };

      putenv('BOOTGLY_CERTIFIER_ROOT=' . BOOTGLY_ROOT_BASE);
      putenv("BOOTGLY_CERTIFIER_STORAGE={$storage}");
      putenv("BOOTGLY_CERTIFIER_PORT={$port}");
      putenv("BOOTGLY_CERTIFIER_GATE={$gate}");
      putenv("BOOTGLY_CERTIFIER_CA={$CA}");

      $Listener = @stream_socket_server(
         "tcp://127.0.0.1:{$CA}",
         $code,
         $message,
         STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
      );
      if ($Listener !== false) {
         $blackhole = pcntl_fork();
         if ($blackhole === 0) {
            $Peer = @stream_socket_accept($Listener, 15.0);
            if ($Peer !== false) {
               usleep(15000000);
               fclose($Peer);
            }
            fclose($Listener);
            exit(0);
         }
         fclose($Listener);
      }

      try {
         $Launcher = proc_open(
            [PHP_BINARY, __DIR__ . '/certifier.php'],
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

         $held = $started && $master > 1 && $Wait($Held);
         if ($held) {
            $PIDs = $Children($master);
            posix_kill($master, SIGKILL);
         }

         $released = $held && $Wait(static function () use ($Held): bool {
            return $Held() === false;
         });
         $stopped = $released && $Wait(static function () use ($Alive, &$PIDs): bool {
            foreach ($PIDs as $PID) {
               if ($Alive($PID)) {
                  return false;
               }
            }

            return true;
         });

         yield assert(
            assertion: $started && $held && $released && $stopped,
            description: 'master SIGKILL interrupts the stalled certifier and releases its renewal lock with all owned children stopped'
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
         if ($blackhole > 0 && $Alive($blackhole)) {
            posix_kill($blackhole, SIGKILL);
         }
         if ($blackhole > 0) {
            pcntl_waitpid($blackhole, $status);
         }

         putenv('BOOTGLY_CERTIFIER_ROOT');
         putenv('BOOTGLY_CERTIFIER_STORAGE');
         putenv('BOOTGLY_CERTIFIER_PORT');
         putenv('BOOTGLY_CERTIFIER_GATE');
         putenv('BOOTGLY_CERTIFIER_CA');

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
