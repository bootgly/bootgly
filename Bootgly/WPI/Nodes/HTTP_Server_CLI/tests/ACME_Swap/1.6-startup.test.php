<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;

return new Specification(
   description: 'ACME(startup): daemon readiness fails when any worker rejects its initial credential',
   test: function () {
      $storage = sys_get_temp_dir() . '/bootgly-autotls-startup-' . getmypid();
      $port = 18111;
      $gate = 18079;

      putenv('BOOTGLY_STARTUP_ROOT=' . BOOTGLY_ROOT_BASE);
      putenv("BOOTGLY_STARTUP_STORAGE={$storage}");
      putenv("BOOTGLY_STARTUP_PORT={$port}");
      putenv("BOOTGLY_STARTUP_GATE={$gate}");

      $started = microtime(true);
      try {
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
            assertion: $status !== 0 && $elapsed < 15.0 && $released,
            description: 'the launcher reports failure and leaves neither worker listener nor challenge helper behind'
         );
      }
      finally {
         putenv('BOOTGLY_STARTUP_ROOT');
         putenv('BOOTGLY_STARTUP_STORAGE');
         putenv('BOOTGLY_STARTUP_PORT');
         putenv('BOOTGLY_STARTUP_GATE');

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
