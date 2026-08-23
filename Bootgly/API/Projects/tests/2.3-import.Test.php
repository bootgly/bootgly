<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Projects;


return new Test(
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
         description: 'imports a source carrying the *.Project.php signature'
      );
      yield assert(
         assertion: is_file("{$base}Imported/Imported.Project.php") === true,
         description: 'the signature file is renamed to the new leaf'
      );

      $content = (string) file_get_contents("{$base}Imported/Imported.Project.php");
      yield assert(
         assertion: str_contains($content, "'name' => 'Sample'") === true,
         description: 'the imported content is kept as-is — only the signature file is renamed'
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

      // @ Every gate register() applies runs BEFORE the copy — a refused path
      //   leaves nothing on disk and nothing in the registry
      $done = Projects::import("{$fixtures}/Sample", 'Bootgly/Evil', ['interfaces' => ['WPI']], $base);
      yield assert(
         assertion: $done === false && is_dir("{$base}Bootgly") === false,
         description: 'a reserved root is refused before anything is copied'
      );
      $done = Projects::import("{$fixtures}/Sample", 'NoIface', [], $base);
      yield assert(
         assertion: $done === false && is_dir("{$base}NoIface") === false,
         description: 'an empty interfaces list is refused before anything is copied'
      );

      // @ A refresh keeps the old copy until the new one is complete, then
      //   replaces it whole — and the staging sibling never outlives the call
      //   (a stale one left by a crash is cleared too)
      file_put_contents("{$base}Imported/stale.txt", 'from the previous copy');
      mkdir("{$base}.Imported.staging", 0755, true);
      file_put_contents("{$base}.Imported.staging/junk", 'crash leftover');
      $done = Projects::import("{$fixtures}/Sample", 'Imported', ['interfaces' => ['CLI']], $base, refresh: true);
      $registry = include "{$base}Bootgly.projects.php";
      yield assert(
         assertion: $done === true
            && is_file("{$base}Imported/stale.txt") === false
            && is_file("{$base}Imported/Imported.Project.php") === true
            && ($registry['Imported']['interfaces'] ?? null) === ['CLI'],
         description: 'a refresh replaces the existing copy whole and re-registers it'
      );
      yield assert(
         assertion: is_dir("{$base}.Imported.staging") === false,
         description: 'no staging sibling is left behind'
      );

      // @ A source that IS the target is left in place (the framework checkout)
      $done = Projects::import("{$base}Imported", 'Imported', ['interfaces' => ['WPI']], $base, refresh: true);
      $registry = include "{$base}Bootgly.projects.php";
      yield assert(
         assertion: $done === true
            && is_file("{$base}Imported/Imported.Project.php") === true
            && ($registry['Imported']['interfaces'] ?? null) === ['CLI'],
         description: 'importing a project onto itself keeps it, and its registry entry, untouched'
      );

      // @ A registry that cannot be written rolls the import back — and on a
      //   refresh the OLD copy is the one that survives
      $broken = sys_get_temp_dir() . '/bootgly-test-import-broken-' . getmypid() . '/';
      $erase(rtrim($broken, '/'));
      mkdir("{$broken}Bootgly.projects.php", 0755, true);
      file_put_contents("{$broken}Bootgly.projects.php/occupant", 'x');
      $threw = false;
      $returned = null;
      try {
         $returned = Projects::import("{$fixtures}/Sample", 'Fresh', ['interfaces' => ['WPI']], $broken);
      }
      catch (Throwable) {
         $threw = true;
      }
      yield assert(
         assertion: ($returned === false || $threw === true)
            && is_dir("{$broken}Fresh") === false
            && is_dir("{$broken}.Fresh.staging") === false,
         description: 'a new path whose registration fails is rolled back, staging included'
      );
      mkdir("{$broken}Kept", 0755, true);
      file_put_contents("{$broken}Kept/Kept.Project.php", "<?php\nreturn ['name' => 'old'];\n");
      $threw = false;
      $returned = null;
      try {
         $returned = Projects::import("{$fixtures}/Sample", 'Kept', ['interfaces' => ['WPI']], $broken, refresh: true);
      }
      catch (Throwable) {
         $threw = true;
      }
      yield assert(
         assertion: ($returned === false || $threw === true)
            && (string) file_get_contents("{$broken}Kept/Kept.Project.php") === "<?php\nreturn ['name' => 'old'];\n"
            && is_dir("{$broken}.Kept.staging") === false,
         description: 'a refresh whose registration fails keeps the old copy untouched'
      );
      $erase(rtrim($broken, '/'));

      $erase(rtrim($base, '/'));
   }
);
