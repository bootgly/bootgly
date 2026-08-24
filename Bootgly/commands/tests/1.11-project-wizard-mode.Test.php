<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\commands;


use const BOOTGLY_ROOT_DIR;
use const GLOB_ONLYDIR;
use function array_diff;
use function assert;
use function escapeshellarg;
use function exec;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function function_exists;
use function fwrite;
use function glob;
use function is_dir;
use function is_file;
use function is_link;
use function is_resource;
use function mkdir;
use function preg_match;
use function preg_replace;
use function proc_close;
use function proc_open;
use function rmdir;
use function scandir;
use function str_contains;
use function stream_get_contents;
use function strpos;
use function unlink;
use ReflectionProperty;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;
use Bootgly\API\Projects;


return new Test(
   description: 'The wizard opens on the imported projects and creating nothing is the first mode',
   test: function () {
      // ! Platforms stopped being a question: a prepared kit carries every
      //   shipped project, so the wizard's Mode step leads with "use what is
      //   already here". Driven through the real binary with a forced TTY —
      //   piping Enter takes the highlighted (first) option.
      // ? proc_open unavailable — nothing to spawn
      if (function_exists('proc_open') === false) {
         yield assert(assertion: true, description: 'Skipped: proc_open is unavailable');
         return;
      }

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

      // ! The count the menu must state, derived independently of the command:
      //   every exportable project sitting in `projects/`, at either depth
      $tally = static function () use ($consumer): int {
         $found = 0;
         foreach ((array) glob("{$consumer}*", GLOB_ONLYDIR) as $dir) {
            $candidates = (array) glob("{$dir}/*.Project.php");
            if ($candidates === []) {
               $candidates = (array) glob("{$dir}/*/*.Project.php");
            }

            foreach ($candidates as $signature) {
               $source = (string) file_get_contents((string) $signature);

               if (preg_match('/exportable\s*:\s*true/', $source) === 1) {
                  $found++;
               }
            }
         }

         return $found;
      };

      // ! One wizard run answered with a bare Enter. The captured stream is
      //   read WITHOUT its escape codes: the renderer paints a label and its
      //   parenthetical in different colors, so a raw match for the finished
      //   step would look for a substring the stream never carries.
      $run = static function (array $arguments = [], string $input = "\n") use (&$output): int {
         $Process = proc_open(
            ['php', BOOTGLY_ROOT_DIR . 'bootgly', 'project', 'create', ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            BOOTGLY_ROOT_DIR,
            ['BOOTGLY_TTY' => '1', 'PATH' => (string) ($_SERVER['PATH'] ?? '/usr/bin:/bin')]
         );

         $output = '';
         $status = -1;
         if (is_resource($Process) === true) {
            fwrite($pipes[0], $input);
            fclose($pipes[0]);
            $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_close($Process);
         }

         $output = (string) preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $output);

         return $status;
      };

      $plant = "{$consumer}PlantedMode";
      // ! A platform source the working directory does NOT import: in a kit
      //   these live under `Console/projects/` and `Web/projects/`
      $ghost = BOOTGLY_ROOT_DIR . 'Console/projects';

      try {
         // ! A run killed before its cleanup leaves the plant behind — never trip on it
         $erase($plant);
         $erase(BOOTGLY_ROOT_DIR . 'Console');

         $available = $tally();
         $status = $run();

         yield assert(
            assertion: $status === 0,
            description: "a wizard answered with Enter exits 0 (got {$status})"
         );
         yield assert(
            assertion: str_contains($output, "Only skip to imported from Platforms ({$available} available)"),
            description: "the first mode counts the {$available} imported projects the kit holds"
         );

         // # Order: the skip mode leads, the two creating modes follow
         $skip = strpos($output, 'Only skip to imported from Platforms');
         $scratch = strpos($output, 'Create project from scratch');
         $git = strpos($output, 'Import project from Git remote');

         yield assert(
            assertion: $skip !== false && $scratch !== false && $git !== false
               && $skip < $scratch && $scratch < $git,
            description: 'the three modes are offered with the skip mode first'
         );
         yield assert(
            assertion: str_contains($output, 'Mode (imported projects)'),
            description: 'Enter on the first mode resolves to the imported-projects branch'
         );
         yield assert(
            assertion: str_contains($output, 'bootgly project list')
               && str_contains($output, 'bootgly project <Name> start'),
            description: 'the branch closes by pointing at the imported projects'
         );

         yield assert(
            assertion: str_contains($output, "Imported projects ({$available})")
               && str_contains($output, 'Bootgly'),
            description: 'the skip branch closes with a summary of what the kit holds'
         );

         yield assert(
            assertion: str_contains($output, "Imported projects ({$available})")
               && str_contains($output, 'Bootgly'),
            description: 'the skip branch closes with a summary of what the kit holds'
         );

         // # It creates NOTHING — the registry is the one the run started with
         $after = is_file($registry) ? file_get_contents($registry) : null;
         yield assert(
            assertion: $after === $snapshot,
            description: 'the skip mode registers no project'
         );

         // # The count is live: an exportable project planted in `projects/`
         //   is one more guide the menu has to report
         mkdir($plant, 0755, true);
         file_put_contents(
            "{$plant}/PlantedMode.Project.php",
            "<?php\n\nuse Bootgly\\API\\Projects\\Project;\n\n"
               . "return new Project(boot: static function (): void {}, exportable: true, name: 'PlantedMode');\n"
         );

         $run();
         $planted = $available + 1;

         yield assert(
            assertion: str_contains($output, "Only skip to imported from Platforms ({$planted} available)"),
            description: "the count follows what the kit holds ({$planted} with the plant)"
         );

         // # ... and only what it HOLDS: a platform source that was never
         //   imported is a project the user cannot open, so it is not offered
         mkdir("{$ghost}/PlantedGhost", 0755, true);
         file_put_contents(
            "{$ghost}/PlantedGhost/PlantedGhost.Project.php",
            "<?php\n\nuse Bootgly\\API\\Projects\\Project;\n\n"
               . "return new Project(boot: static function (): void {}, exportable: true, name: 'PlantedGhost');\n"
         );

         $run();

         yield assert(
            assertion: str_contains($output, "Only skip to imported from Platforms ({$planted} available)")
               && str_contains($output, 'PlantedGhost') === false,
            description: 'a platform source that was never imported is not counted'
         );
         // # From scratch: the run closes with a receipt of what it generated,
         //   and every next step names the project — a `<Name>` placeholder
         //   belongs to the branch that created nothing, not to this one
         $run(['--from=scratch', '--interfaces=CLI'], "WizSum\nA wizard summary\n2.0.0\nRodrigo\n\n");

         yield assert(
            assertion: str_contains($output, 'Project WizSum')
               && str_contains($output, 'projects/WizSum/')
               && str_contains($output, 'A wizard summary')
               && str_contains($output, '2.0.0')
               && str_contains($output, 'Rodrigo'),
            description: 'a from-scratch run closes with a summary of what it generated'
         );
         yield assert(
            assertion: str_contains($output, 'bootgly project WizSum start')
               && str_contains($output, '<Name>') === false,
            description: 'the from-scratch tips name the project, never a placeholder'
         );

         // # From a git remote: the same receipt, plus where it came from.
         //   Driven through the Mode menu with two ↓ and Enter — `file://` on
         //   purpose, git treats a plain local path differently.
         $fixture = Temporaries::reserve('wizard-clone');
         $identity = '-c user.email=probe@local -c user.name=probe -c commit.gpgsign=false';
         file_put_contents(
            "{$fixture}/WizClone.Project.php",
            "<?php\n\nuse Bootgly\\API\\Projects\\Project;\n\n"
               . "return new Project(boot: static function (): void {}, name: 'WizClone');\n"
         );
         exec('git -C ' . escapeshellarg($fixture) . ' init --quiet 2>/dev/null');
         exec('git -C ' . escapeshellarg($fixture) . ' add WizClone.Project.php 2>/dev/null');
         exec('git -C ' . escapeshellarg($fixture) . " {$identity} commit --quiet -m one 2>/dev/null");

         $run([], "\033[B\033[B\nfile://{$fixture}\nWizClone\n\ny\n");

         yield assert(
            assertion: str_contains($output, 'Project WizClone')
               && str_contains($output, 'projects/WizClone/')
               && str_contains($output, "file://{$fixture}")
               && str_contains($output, 'bootgly project WizClone start')
               && str_contains($output, '<Name>') === false,
            description: 'a git-remote run summarizes the clone and names it in the tips'
         );
      }
      finally {
         // ! Leave the checkout exactly as it was found
         $erase($plant);
         foreach (['WizSum', 'WizClone', '.WizClone.staging', '.WizClone.backup'] as $probe) {
            $erase("{$consumer}{$probe}");
         }
         $erase(BOOTGLY_ROOT_DIR . 'Console');

         if ($snapshot !== null && $snapshot !== false) {
            file_put_contents($registry, $snapshot);
         }
         $Memo->setValue(null, null);
      }
   }
);
