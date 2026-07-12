<?php

use Bootgly\ACI\Process;
use Bootgly\ACI\Tests\Suite\Test\Specification;

return new Specification(
   description: 'ACME(helper): cross-process delegation requires an exact ready spool lease',
   test: function () {
      $Server = $GLOBALS['BOOTGLY_ACME_SWAP']['Server'];
      $base = sys_get_temp_dir() . '/bootgly-acme-lease-' . getmypid() . '/';

      $Run = static function (string $challenges, string $name) use ($base): null|array {
         $storage = "{$base}{$name}/";
         $result = "{$base}{$name}.json";
         putenv('BOOTGLY_LEASE_ROOT=' . BOOTGLY_ROOT_BASE);
         putenv("BOOTGLY_LEASE_STORAGE={$storage}");
         putenv("BOOTGLY_LEASE_CHALLENGES={$challenges}");
         putenv("BOOTGLY_LEASE_RESULT={$result}");

         $Process = proc_open(
            [PHP_BINARY, __DIR__ . '/lease.php'],
            [
               ['file', '/dev/null', 'r'],
               ['file', '/dev/null', 'a'],
               ['file', '/dev/null', 'a']
            ],
            $Pipes,
            BOOTGLY_ROOT_BASE
         );
         if (is_resource($Process)) {
            proc_close($Process);
         }
         $JSON = @file_get_contents($result);
         $decoded = is_string($JSON) ? json_decode($JSON, true) : null;

         putenv('BOOTGLY_LEASE_ROOT');
         putenv('BOOTGLY_LEASE_STORAGE');
         putenv('BOOTGLY_LEASE_CHALLENGES');
         putenv('BOOTGLY_LEASE_RESULT');

         return is_array($decoded) ? $decoded : null;
      };

      try {
         $Watched = new ReflectionProperty($Server, 'watched');
         $Checked = new ReflectionProperty($Server, 'checked');
         $Watched->setValue($Server, 0);
         $Checked->setValue($Server, time() + 3600);
         $Supervise = new ReflectionMethod($Server, 'supervise');
         $Supervise->invoke($Server);

         $shared = $Run($Server->AutoTLS->challenges, 'shared');
         yield assert(
            assertion: $shared !== null
               && $shared['ready'] === true
               && $shared['local'] === false
               && $shared['validator'] === Process::$master,
            description: 'a second process consumes the live helper only for the exact same port and spool'
         );

         $isolated = $Run("{$base}other-challenges/", 'isolated');
         yield assert(
            assertion: $isolated !== null
               && $isolated['ready'] === false
               && $isolated['local'] === false
               && $isolated['validator'] === 0,
            description: 'a different challenge spool cannot borrow the occupied validation port'
         );
      }
      finally {
         if (is_dir($base)) {
            $Iterator = new RecursiveIteratorIterator(
               new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
               RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($Iterator as $Entry) {
               $Entry->isDir()
                  ? @rmdir($Entry->getPathname())
                  : @unlink($Entry->getPathname());
            }
            @rmdir($base);
         }
      }
   }
);
