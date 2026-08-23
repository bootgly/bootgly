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
      //   (a stale staging or backup left by a crash is cleared too)
      file_put_contents("{$base}Imported/stale.txt", 'from the previous copy');
      mkdir("{$base}.Imported.staging", 0755, true);
      file_put_contents("{$base}.Imported.staging/junk", 'crash leftover');
      mkdir("{$base}.Imported.backup", 0755, true);
      file_put_contents("{$base}.Imported.backup/junk", 'previous remainder');
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
         assertion: is_dir("{$base}.Imported.staging") === false && is_dir("{$base}.Imported.backup") === false,
         description: 'no staging or backup sibling is left behind'
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

      // @ The old copy holds a subtree the process cannot remove — the refresh
      //   still completes, consistently: the new copy is in place, the
      //   registry matches it, and only the undeletable remainder stays aside
      //   as the backup directory. And the NEXT refresh of the same path must
      //   not choke on that remainder: it backs up beside it and completes too.
      mkdir("{$base}Stuck/locked", 0755, true);
      file_put_contents("{$base}Stuck/Stuck.Project.php", "<?php\nreturn ['name' => 'old'];\n");
      file_put_contents("{$base}Stuck/locked/inside.txt", 'x');
      chmod("{$base}Stuck/locked", 0555);
      $rounds = [];
      foreach ([1, 2] as $round) {
         $threw = false;
         $returned = null;
         try {
            $returned = Projects::import("{$fixtures}/Sample", 'Stuck', ['interfaces' => ['WPI']], $base, refresh: true);
         }
         catch (Throwable) {
            $threw = true;
         }
         $rounds[$round] = $returned === true && $threw === false;
      }
      $registry = include "{$base}Bootgly.projects.php";
      yield assert(
         assertion: $rounds === [1 => true, 2 => true]
            && (string) file_get_contents("{$base}Stuck/Stuck.Project.php") !== "<?php\nreturn ['name' => 'old'];\n"
            && ($registry['Stuck']['interfaces'] ?? null) === ['WPI']
            && is_dir("{$base}.Stuck.staging") === false
            && is_file("{$base}.Stuck.backup/locked/inside.txt") === true
            && count(glob("{$base}.Stuck.backup*", GLOB_ONLYDIR) ?: []) === 1,
         description: 'a refresh over a copy with an undeletable subtree completes twice, the remainder aside, found: '
            . json_encode($rounds)
      );
      chmod("{$base}.Stuck.backup/locked", 0755);

      // @ A special file in the old copy (a leftover FIFO or socket) is
      //   removed with it — nothing of the old copy stays behind
      if (function_exists('posix_mkfifo') === true) {
         mkdir("{$base}Fifo", 0755, true);
         file_put_contents("{$base}Fifo/Fifo.Project.php", "<?php\nreturn ['name' => 'old'];\n");
         posix_mkfifo("{$base}Fifo/server.fifo", 0600);
         $done = Projects::import("{$fixtures}/Sample", 'Fifo', ['interfaces' => ['WPI']], $base, refresh: true);
         yield assert(
            assertion: $done === true
               && is_dir("{$base}.Fifo.backup") === false
               && file_exists("{$base}Fifo/server.fifo") === false,
            description: 'a refresh over a copy holding a FIFO removes the old copy whole'
         );
      }

      // @ A backup with no project beside it is a refresh that died between
      //   its two renames — it is the user's project, and it goes back first
      mkdir("{$base}.Crash.backup", 0755, true);
      file_put_contents("{$base}.Crash.backup/Crash.Project.php", "<?php\nreturn ['name' => 'mine'];\n");
      $done = Projects::import("{$fixtures}/Sample", 'Crash', ['interfaces' => ['WPI']], $base);
      yield assert(
         assertion: $done === false
            && (string) file_get_contents("{$base}Crash/Crash.Project.php") === "<?php\nreturn ['name' => 'mine'];\n"
            && is_dir("{$base}.Crash.backup") === false,
         description: 'an interrupted refresh is put back before a new import can claim the path'
      );
      $done = Projects::import("{$fixtures}/Sample", 'Crash', ['interfaces' => ['WPI']], $base, refresh: true);
      yield assert(
         assertion: $done === true
            && (string) file_get_contents("{$base}Crash/Crash.Project.php") !== "<?php\nreturn ['name' => 'mine'];\n",
         description: '…and a refresh then replaces it as usual'
      );

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
