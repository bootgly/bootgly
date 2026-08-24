<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Projects;


return new Test(
   description: 'Projects::load() includes a project Composer autoloader exactly once',
   test: function () {
      // ! A project directory with a vendor autoloader that counts its runs
      $base = sys_get_temp_dir() . '/bootgly-test-load-' . getmypid() . '/';
      $erase = function (string $target) use (&$erase): void {
         if (is_file($target) === true) {
            unlink($target);
            return;
         }
         if (is_dir($target) === false) {
            return;
         }
         foreach ((array) scandir($target) as $entry) {
            if ($entry === '.' || $entry === '..') {
               continue;
            }
            $erase("{$target}/{$entry}");
         }
         rmdir($target);
      };
      $erase(rtrim($base, '/'));
      mkdir("{$base}App/vendor", 0755, true);
      mkdir("{$base}Plain", 0755, true);
      file_put_contents(
         "{$base}App/vendor/autoload.php",
         "<?php\n\n\$GLOBALS['BOOTGLY_TEST_LOAD'] = (\$GLOBALS['BOOTGLY_TEST_LOAD'] ?? 0) + 1;\n"
      );

      try {
         // @ Loads once
         Projects::load("{$base}App/");

         yield assert(
            assertion: ($GLOBALS['BOOTGLY_TEST_LOAD'] ?? 0) === 1,
            description: 'the project vendor autoloader is included'
         );

         // @ A second call is a no-op — require_once guards the re-entry
         Projects::load("{$base}App/");

         yield assert(
            assertion: ($GLOBALS['BOOTGLY_TEST_LOAD'] ?? 0) === 1,
            description: 'a repeated load never includes it twice'
         );

         // @ No vendor/: silent no-op
         Projects::load("{$base}Plain/");

         yield assert(
            assertion: ($GLOBALS['BOOTGLY_TEST_LOAD'] ?? 0) === 1,
            description: 'a project without vendor/ is a silent no-op'
         );
      }
      finally {
         unset($GLOBALS['BOOTGLY_TEST_LOAD']);
         $erase(rtrim($base, '/'));
      }
   }
);
