<?php

namespace Bootgly\commands;


use function array_diff;
use function assert;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function is_file;
use function is_link;
use function mkdir;
use function rmdir;
use function scandir;
use function unlink;
use ReflectionProperty;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Projects;


return new Test(
   description: 'It should refresh a platform copy without a destructive window',
   test: function () {
      // ! `create --from=<source>` refreshes an existing user-level copy. It used
      //   to erase that copy first and validate afterwards; every probe below
      //   drives the real route against a platform project planted in the
      //   author folder, which trace() resolves first.
      $Command = new ProjectsCommand;

      $registry = Projects::CONSUMER_DIR . 'Bootgly.projects.php';
      $snapshot = is_file($registry) ? file_get_contents($registry) : null;
      $Memo = new ReflectionProperty(Projects::class, 'registry');

      $erase = function (string $target) use (&$erase): void {
         if (is_link($target) === true || is_file($target) === true) {
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

      $source = Projects::AUTHOR_DIR . 'PlantedSrc';
      $options = ['yes' => true, 'platform' => 'none', 'from' => 'PlantedSrc'];

      try {
         // ! A run killed before its cleanup leaves the plant behind — never trip on it
         $erase($source);
         mkdir($source, 0755, true);
         file_put_contents(
            "{$source}/PlantedSrc.Project.php",
            "<?php\n\nuse Bootgly\\API\\Projects\\Project;\n\n"
               . "return new Project(boot: static function (): void {}, exportable: true, name: 'PlantedSrc');\n"
         );

         // # A reserved root is refused BEFORE the existing copy is touched
         $planted = Projects::CONSUMER_DIR . 'Data/App';
         mkdir($planted, 0755, true);
         file_put_contents("{$planted}/USER_WORK.txt", 'survives');
         $returned = $Command->create(['Data/App'], $options);
         yield assert(
            assertion: $returned === false
               && is_file("{$planted}/USER_WORK.txt") === true
               && is_file("{$planted}/App.Project.php") === false,
            description: 'a reserved root is refused before the user copy is erased or overwritten'
         );

         // # A project that is its own source (the framework checkout) is kept
         $returned = $Command->create([], $options);
         yield assert(
            assertion: $returned === true && is_file("{$source}/PlantedSrc.Project.php") === true,
            description: 'importing a platform project onto itself keeps the source in place'
         );

         // # A refresh replaces a stale project copy whole, leaving no staging
         //   or backup behind
         $refreshed = Projects::CONSUMER_DIR . 'Refreshed';
         mkdir($refreshed, 0755, true);
         file_put_contents("{$refreshed}/Refreshed.Project.php", "<?php\nreturn null;\n");
         file_put_contents("{$refreshed}/stale.txt", 'from the previous copy');
         $returned = $Command->create(['Refreshed'], $options);
         yield assert(
            assertion: $returned === true
               && is_file("{$refreshed}/stale.txt") === false
               && (string) file_get_contents("{$refreshed}/Refreshed.Project.php") !== "<?php\nreturn null;\n"
               && is_dir(Projects::CONSUMER_DIR . '.Refreshed.staging') === false
               && is_dir(Projects::CONSUMER_DIR . '.Refreshed.backup') === false,
            description: 'a refresh replaces the existing copy whole and leaves no staging sibling'
         );

         // # A directory without a signature is not a project copy — never replaced
         $handmade = Projects::CONSUMER_DIR . 'Handmade';
         mkdir($handmade, 0755, true);
         file_put_contents("{$handmade}/USER_WORK.txt", 'survives');
         $returned = $Command->create(['Handmade'], $options);
         yield assert(
            assertion: $returned === false
               && is_file("{$handmade}/USER_WORK.txt") === true
               && is_file("{$handmade}/Handmade.Project.php") === false,
            description: 'a hand-made directory at the target is refused, not replaced'
         );
      }
      finally {
         // ! A regression erases or strands; leave the repository as it was found.
         // ! The source was planted in the framework's own projects/ — in a kit
         //   that is not the consumer dir, so it is erased where it was planted
         $erase($source);
         foreach (['PlantedSrc', 'Data', 'Refreshed', '.Refreshed.staging', '.Refreshed.backup', 'Handmade'] as $probe) {
            $erase(Projects::CONSUMER_DIR . $probe);
         }
         if ($snapshot !== null && $snapshot !== false) {
            file_put_contents($registry, $snapshot);
         }
         $Memo->setValue(null, null);
      }
   }
);
