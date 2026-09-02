<?php

namespace Bootgly\commands;


use const BOOTGLY_ROOT_DIR;
use function array_diff;
use function assert;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function fwrite;
use function is_array;
use function is_dir;
use function is_file;
use function is_link;
use function is_resource;
use function mkdir;
use function preg_replace;
use function proc_close;
use function proc_open;
use function rmdir;
use function scandir;
use function stream_get_contents;
use function substr;
use function unlink;
use ReflectionProperty;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Projects;


return new Test(
   description: 'It should import to the <Name> the wizard was given, not to the source path',
   test: function () {
      // ! The interactive route (`create <Name> --from=<Src>` on a TTY, no
      //   --yes) runs the wizard, whose Mode step used to build the import
      //   from the SOURCE path and drop <Name> — the same command created a
      //   different project depending on whether a TTY was attached. Driven
      //   here through the real binary with a forced TTY and piped answers.
      // ! The child runs the FRAMEWORK launcher, so it writes to the framework's
      //   own projects/ — not this process's (a kit, when the framework is its submodule)
      $consumer = BOOTGLY_ROOT_DIR . 'projects/';
      $registry = "{$consumer}Bootgly.projects.php";
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

      $source = Projects::AUTHOR_DIR . 'PlantedWiz';

      try {
         // ! A run killed before its cleanup leaves the plant behind — never trip on it
         $erase($source);
         mkdir($source, 0755, true);
         file_put_contents(
            "{$source}/PlantedWiz.Project.php",
            "<?php\n\nuse Bootgly\\API\\Projects\\Project;\n\n"
               . "return new Project(boot: static function (): void {}, exportable: true, name: 'PlantedWiz');\n"
         );

         $Process = proc_open(
            ['php', BOOTGLY_ROOT_DIR . 'bootgly', 'projects', 'create', 'WizName', '--from=PlantedWiz', '--platform=none'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            BOOTGLY_ROOT_DIR,
            ['BOOTGLY_TTY' => '1', 'PATH' => (string) ($_SERVER['PATH'] ?? '/usr/bin:/bin')]
         );
         $output = '';
         if (is_resource($Process) === true) {
            fwrite($pipes[0], "y\ny\n");
            fclose($pipes[0]);
            $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($Process);
         }

         $loaded = is_file($registry) ? include $registry : [];
         yield assert(
            assertion: is_file("{$consumer}WizName/WizName.Project.php") === true
               && is_array($loaded) && isset($loaded['WizName']),
            description: 'the wizard imports to <Name>, output: ' . substr(preg_replace('/\s+/', ' ', $output) ?? '', -200)
         );
         yield assert(
            assertion: is_file("{$source}/PlantedWiz.Project.php") === true,
            description: 'the source project is left in place'
         );

         // # The interfaces come from the SOURCE's registry entry, not from the
         //   new name — a shipped WPI project imported under another name stays WPI
         $Process = proc_open(
            ['php', BOOTGLY_ROOT_DIR . 'bootgly', 'projects', 'create', 'WizWeb', '--from=Demo/HTTP_Server_CLI', '--platform=none'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            BOOTGLY_ROOT_DIR,
            ['BOOTGLY_TTY' => '1', 'PATH' => (string) ($_SERVER['PATH'] ?? '/usr/bin:/bin')]
         );
         if (is_resource($Process) === true) {
            fwrite($pipes[0], "y\ny\n");
            fclose($pipes[0]);
            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($Process);
         }
         $loaded = is_file($registry) ? include $registry : [];
         yield assert(
            assertion: is_array($loaded) && ($loaded['WizWeb']['interfaces'] ?? null) === ['WPI'],
            description: 'a renamed import keeps the interfaces of its source'
         );
      }
      finally {
         // ! A regression imports elsewhere or erases; leave the repository as it was found.
         foreach (['PlantedWiz', 'WizName', '.WizName.staging', '.WizName.backup', 'WizWeb', '.WizWeb.staging', '.WizWeb.backup'] as $probe) {
            $erase("{$consumer}{$probe}");
         }
         if ($snapshot !== null && $snapshot !== false) {
            file_put_contents($registry, $snapshot);
         }
         $Memo->setValue(null, null);
      }
   }
);
