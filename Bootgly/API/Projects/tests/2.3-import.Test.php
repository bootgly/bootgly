<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Projects;


return new Test(
   description: 'Projects::import() imports projects carrying the Bootgly signature',
   test: function () {
      // ! Scratch projects base + fixtures
      $base = sys_get_temp_dir() . '/bootgly-test-import-' . getmypid() . '/';
      $erase = function (string $target) use (&$erase): void {
         // ? Links and special files (the FIFO planted below) go as entries
         if (is_link($target) === true || (file_exists($target) === true && is_dir($target) === false)) {
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

      // @ A refresh replaces a PROJECT — a directory without a signature at its
      //   root (a group of projects, a hand-made tree) is refused untouched
      mkdir("{$base}Group/Keep", 0755, true);
      file_put_contents("{$base}Group/Keep/Keep.Project.php", "<?php\nreturn null;\n");
      file_put_contents("{$base}Group/Keep/USER_WORK.txt", 'mine');
      Projects::register('Group/Keep', ['interfaces' => ['CLI'], 'default' => true], "{$base}Bootgly.projects.php");
      $done = Projects::import("{$fixtures}/Sample", 'Group', ['interfaces' => ['WPI']], $base, refresh: true);
      $registry = include "{$base}Bootgly.projects.php";
      yield assert(
         assertion: $done === false
            && is_file("{$base}Group/Keep/USER_WORK.txt") === true
            && is_file("{$base}Group/Group.Project.php") === false
            && ($registry['Group/Keep']['default'] ?? null) === true,
         description: 'a refresh onto a project group is refused, and the group survives intact'
      );

      // @ …and a source inside the target — a project nested in the project
      //   being refreshed — would be moved aside and deleted with it: refused
      mkdir("{$base}Outer/Inner", 0755, true);
      file_put_contents("{$base}Outer/Outer.Project.php", "<?php\nreturn null;\n");
      file_put_contents("{$base}Outer/Inner/Inner.Project.php", "<?php\nreturn null;\n");
      $done = Projects::import("{$base}Outer/Inner", 'Outer', ['interfaces' => ['WPI']], $base, refresh: true);
      yield assert(
         assertion: $done === false
            && is_file("{$base}Outer/Inner/Inner.Project.php") === true
            && (string) file_get_contents("{$base}Outer/Outer.Project.php") === "<?php\nreturn null;\n",
         description: 'a refresh whose source lives inside the target is refused'
      );

      // @ The old copy holds something the process cannot remove — the
      //   refresh still completes, consistently: the new copy is in place,
      //   the registry matches it, and only the undeletable remainder stays
      //   aside as the backup directory
      mkdir("{$base}Stuck", 0755, true);
      file_put_contents("{$base}Stuck/Stuck.Project.php", "<?php\nreturn ['name' => 'old'];\n");
      if (function_exists('posix_mkfifo') === true) {
         posix_mkfifo("{$base}Stuck/server.fifo", 0600);
      }
      else {
         mkdir("{$base}Stuck/locked", 0755, true);
         file_put_contents("{$base}Stuck/locked/inside.txt", 'x');
         chmod("{$base}Stuck/locked", 0555);
      }
      $threw = false;
      $returned = null;
      try {
         $returned = Projects::import("{$fixtures}/Sample", 'Stuck', ['interfaces' => ['WPI']], $base, refresh: true);
      }
      catch (Throwable) {
         $threw = true;
      }
      $registry = include "{$base}Bootgly.projects.php";
      yield assert(
         assertion: $returned === true && $threw === false
            && (string) file_get_contents("{$base}Stuck/Stuck.Project.php") !== "<?php\nreturn ['name' => 'old'];\n"
            && ($registry['Stuck']['interfaces'] ?? null) === ['WPI']
            && is_dir("{$base}.Stuck.staging") === false,
         description: 'a refresh over a copy with an undeletable file still completes consistently'
      );
      if (is_dir("{$base}.Stuck.backup/locked") === true) {
         chmod("{$base}.Stuck.backup/locked", 0755);
      }

      // @ A registry that cannot be written rolls the import back — the parent
      //   directory a nested path created included — and on a refresh the OLD
      //   copy is the one that survives, with no staging or backup left
      $broken = sys_get_temp_dir() . '/bootgly-test-import-broken-' . getmypid() . '/';
      $erase(rtrim($broken, '/'));
      mkdir("{$broken}Bootgly.projects.php", 0755, true);
      file_put_contents("{$broken}Bootgly.projects.php/occupant", 'x');
      $threw = false;
      $returned = null;
      try {
         $returned = Projects::import("{$fixtures}/Sample", 'Nested/Fresh', ['interfaces' => ['WPI']], $broken);
      }
      catch (Throwable) {
         $threw = true;
      }
      yield assert(
         assertion: ($returned === false || $threw === true)
            && is_dir("{$broken}Nested") === false
            && is_dir("{$broken}.Fresh.staging") === false,
         description: 'a new path whose registration fails is rolled back, parent and staging included'
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
            && is_dir("{$broken}.Kept.staging") === false
            && is_dir("{$broken}.Kept.backup") === false,
         description: 'a refresh whose registration fails keeps the old copy untouched'
      );
      $erase(rtrim($broken, '/'));

      $erase(rtrim($base, '/'));
   }
);
