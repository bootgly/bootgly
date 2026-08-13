<?php

use Bootgly\ACI\Tests\Suite\Test;

return new Test(
   description: 'ACME(startup): daemon readiness fails when any worker rejects its initial credential',
   test: function () {
      $storage = sys_get_temp_dir() . '/bootgly-autotls-startup-' . getmypid();
      $port = 18111;
      $gate = 18079;

      try {
         foreach (['readiness', 'setup', 'post-ready'] as $phase) {
            putenv('BOOTGLY_STARTUP_ROOT=' . BOOTGLY_ROOT_BASE);
            putenv("BOOTGLY_STARTUP_STORAGE={$storage}-{$phase}");
            putenv("BOOTGLY_STARTUP_PORT={$port}");
            putenv("BOOTGLY_STARTUP_GATE={$gate}");
            putenv("BOOTGLY_STARTUP_PHASE={$phase}");

            $started = microtime(true);
            $Process = proc_open(
               [PHP_BINARY, __DIR__ . '/startup.php'],
               [
                  ['file', '/dev/null', 'r'],
                  ['file', '/dev/null', 'a'],
                  ['file', '/dev/null', 'a']
               ],
               $Pipes,
               BOOTGLY_ROOT_BASE
            );
            $status = is_resource($Process) ? proc_close($Process) : 0;
            $elapsed = microtime(true) - $started;

            $ServerSocket = @stream_socket_server("tcp://127.0.0.1:{$port}");
            $GateSocket = @stream_socket_server("tcp://127.0.0.1:{$gate}");
            $released = is_resource($ServerSocket) && is_resource($GateSocket);
            is_resource($ServerSocket) && fclose($ServerSocket);
            is_resource($GateSocket) && fclose($GateSocket);

            yield assert(
               assertion: $status === 1 && $elapsed < 15.0 && $released,
               description: $phase === 'readiness'
                  ? 'readiness rejection leaves no worker listener, challenge helper or namespace lease behind'
                  : ($phase === 'setup'
                     ? 'a retained server that throws before readiness releases its namespace lease and ports'
                     : 'a post-readiness callback failure retains the live lease until explicit stop')
            );
         }
      }
      finally {
         putenv('BOOTGLY_STARTUP_ROOT');
         putenv('BOOTGLY_STARTUP_STORAGE');
         putenv('BOOTGLY_STARTUP_PORT');
         putenv('BOOTGLY_STARTUP_GATE');
         putenv('BOOTGLY_STARTUP_PHASE');

         foreach (["{$storage}-readiness", "{$storage}-setup", "{$storage}-post-ready"] as $directory) {
            if (is_dir($directory) === false) {
               continue;
            }
            $Iterator = new RecursiveIteratorIterator(
               new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
               RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($Iterator as $Entry) {
               $Entry->isDir()
                  ? @rmdir($Entry->getPathname())
                  : @unlink($Entry->getPathname());
            }
            @rmdir($directory);
         }
      }
   }
);
