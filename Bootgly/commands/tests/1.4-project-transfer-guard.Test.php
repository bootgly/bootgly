<?php

namespace Bootgly\commands;


use const BOOTGLY_ROOT_DIR;
use function array_diff;
use function assert;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function is_file;
use function mkdir;
use function rmdir;
use function scandir;
use function unlink;
use ReflectionMethod;
use ReflectionProperty;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Projects;


return new Test(
   description: 'It should never erase a user-level project copy it cannot replace',
   test: function () {
      // ! transfer() is the wizard's platform-import step: it refreshes an
      //   existing user-level copy by ERASING it and importing the platform
      //   source over it. Both refusals below used to come AFTER that erase.
      $Transfer = new ReflectionMethod(ProjectsCommand::class, 'transfer');
      $Command = new ProjectsCommand;

      $registry = Projects::CONSUMER_DIR . 'Bootgly.projects.php';
      $snapshot = is_file($registry) ? file_get_contents($registry) : null;
      $Memo = new ReflectionProperty(Projects::class, 'registry');

      $erase = function (string $target) use (&$erase): void {
         if (is_file($target) === true) {
            unlink($target);
            return;
         }
         if (is_dir($target) === false) {
            return;
         }
         foreach (array_diff((array) scandir($target), ['.', '..']) as $entry) {
            $erase("{$target}/{$entry}");
         }
         rmdir($target);
      };

      try {
         // # A platform project whose name is outside the naming alphabet —
         //   import() refuses it, so the copy must be refused BEFORE the erase
         $planted = Projects::CONSUMER_DIR . 'bad-app';
         mkdir($planted, 0755, true);
         file_put_contents("{$planted}/marker", 'survives');

         $imported = $Transfer->invoke($Command, [
            ['path' => 'bad-app', 'source' => BOOTGLY_ROOT_DIR . 'Bootgly/API/Projects/tests/fixtures/Sample'],
         ]);
         yield assert(
            assertion: $imported === [] && is_file("{$planted}/marker") === true,
            description: 'a non-conforming platform path is refused before the user copy is erased'
         );

         // # In the framework checkout the platform folder IS the working
         //   projects folder — a picked project is its own source, and the
         //   refresh erase would delete it before import() could read it
         $self = Projects::CONSUMER_DIR . 'SelfProbe';
         mkdir($self, 0755, true);
         file_put_contents("{$self}/SelfProbe.Project.php", "<?php\nreturn null;\n");

         $imported = $Transfer->invoke($Command, [
            ['path' => 'SelfProbe', 'source' => $self],
         ]);
         yield assert(
            assertion: $imported === ['SelfProbe'] && is_file("{$self}/SelfProbe.Project.php") === true,
            description: 'a project that is its own source is kept in place, not erased'
         );
      }
      finally {
         // ! A regression erases — leave the repository as it was found.
         foreach (['bad-app', 'SelfProbe'] as $probe) {
            $erase(Projects::CONSUMER_DIR . $probe);
         }
         if ($snapshot !== null && $snapshot !== false) {
            file_put_contents($registry, $snapshot);
         }
         $Memo->setValue(null, null);
      }
   }
);
