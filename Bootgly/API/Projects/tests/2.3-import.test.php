<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Projects;


return new Specification(
   description: 'Projects::import() imports projects carrying the Bootgly signature',
   test: function () {
      // ! Scratch projects base + fixtures
      $base = sys_get_temp_dir() . '/bootgly-test-import-' . getmypid() . '/';
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
      mkdir($base, 0755, true);

      $fixtures = __DIR__ . '/fixtures';

      // @ Import a valid source under a new leaf
      $done = Projects::import("{$fixtures}/Sample", 'Imported', ['interfaces' => ['WPI']], $base);

      yield assert(
         assertion: $done === true,
         description: 'imports a source carrying the *.project.php signature'
      );
      yield assert(
         assertion: is_file("{$base}Imported/Imported.project.php") === true,
         description: 'the signature file is renamed to the new leaf'
      );

      $content = (string) file_get_contents("{$base}Imported/Imported.project.php");
      yield assert(
         assertion: str_contains($content, "'name' => 'Imported'") === true,
         description: 'old leaf references inside the project file are renamed'
      );

      $registry = include "{$base}Bootgly.projects.php";
      yield assert(
         assertion: ($registry['Imported']['interfaces'] ?? null) === ['WPI'],
         description: 'the imported project is registered in the allow-list'
      );

      // ! Rejections
      yield assert(
         assertion: Projects::import("{$fixtures}/Invalid", 'Invalid2', ['interfaces' => ['WPI']], $base) === false,
         description: 'a source without the signature is refused'
      );
      yield assert(
         assertion: Projects::import("{$fixtures}/Sample", 'Imported', ['interfaces' => ['WPI']], $base) === false,
         description: 'an existing target directory is refused'
      );
      yield assert(
         assertion: Projects::import("{$fixtures}/missing", 'Missing', ['interfaces' => ['WPI']], $base) === false,
         description: 'a missing source directory is refused'
      );

      $erase(rtrim($base, '/'));
   }
);
