<?php

namespace Bootgly\commands;


use const BOOTGLY_ROOT_BASE;
use const PHP_BINARY;
use function assert;
use function escapeshellarg;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function is_file;
use function mkdir;
use function rmdir;
use function shell_exec;
use function str_contains;
use function sys_get_temp_dir;
use function trim;
use function uniqid;
use function unlink;

use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: '`project start` registers console-only projects in the pids registry (and the TUI adopts it)',
   test: function () {
      // ! Scratch working base: registry + one console-only project
      $base = sys_get_temp_dir() . '/bootgly-clistate-' . uniqid();
      mkdir("$base/projects/Scratch", 0o775, true);
      mkdir("$base/storage", 0o755, true);

      file_put_contents(
         "$base/projects/Bootgly.projects.php",
         "<?php return ['Scratch' => ['interfaces' => ['CLI']]];"
      );
      file_put_contents("$base/projects/Scratch/Scratch.Project.php", <<<'PROJECT'
      <?php

      use const Bootgly\CLI;
      use Bootgly\ACI\Process\States;
      use Bootgly\API\Projects;
      use Bootgly\API\Projects\Project;

      return new Project(
         boot: static function (): void {
            $id = Projects::encode('Scratch');
            $instance = (string) posix_getpid();

            // # The launcher registered this instance before booting
            $located = States::locate($id, $instance);
            echo 'in-boot:' . ($located !== null && $located['type'] === 'CLI' ? 'yes' : 'no') . "\n";

            // # A TUI boot adopts the launcher's entry instead of throwing on the held lock
            CLI->Terminal->Input->reading(
               static function ($read, $write): void {},
               static function ($reading): void {}
            );
            $after = States::locate($id, $instance);
            echo 'post-reading:' . ($after !== null ? 'kept' : 'cleaned') . "\n";
         },
         exportable: false,
         name: 'Scratch'
      );
      PROJECT);

      // @ Run the launcher fixture in a child (BOOTGLY_PROJECT is once-per-process)
      $environment = 'BOOTGLY_CLI_STATE_ROOT=' . escapeshellarg(BOOTGLY_ROOT_BASE)
         . ' BOOTGLY_CLI_STATE_BASE=' . escapeshellarg($base);
      $fixture = escapeshellarg(__DIR__ . '/fixtures/cli_state_probe.php');
      $output = (string) shell_exec("$environment " . escapeshellarg(PHP_BINARY) . " $fixture 2>/dev/null");

      yield assert(
         assertion: str_contains($output, 'in-boot:yes'),
         description: 'the console project locates its own registry identity during boot — got: ' . trim($output)
      );

      yield assert(
         assertion: str_contains($output, 'post-reading:kept'),
         description: 'Input::reading() adopts the launcher-held lock (no throw, no clean)'
      );

      // # After exit, the launcher's shutdown hook tombstoned the entry
      $entries = (array) glob("$base/storage/pids/Scratch.*.json");
      $tombstoned = true;
      foreach ($entries as $entry) {
         if (is_file((string) $entry) && (string) file_get_contents((string) $entry) !== '') {
            $tombstoned = false;
         }
      }
      yield assert(
         assertion: $entries !== [] && $tombstoned === true,
         description: 'process exit tombstones the console instance state'
      );

      // @ Cleanup
      foreach ((array) glob("$base/storage/pids/*") as $file) {
         @unlink((string) $file);
      }
      @unlink("$base/projects/Scratch/Scratch.Project.php");
      @unlink("$base/projects/Bootgly.projects.php");
      @rmdir("$base/projects/Scratch");
      @rmdir("$base/projects");
      @rmdir("$base/storage/pids");
      @rmdir("$base/storage");
      @rmdir($base);
   }
);
