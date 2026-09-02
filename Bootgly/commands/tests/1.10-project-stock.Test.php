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
use const PHP_BINARY;
use function array_diff;
use function assert;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function function_exists;
use function getenv;
use function is_dir;
use function is_file;
use function is_link;
use function is_resource;
use function mkdir;
use function proc_close;
use function proc_open;
use function rmdir;
use function scandir;
use function str_contains;
use function stream_get_contents;
use function unlink;

use const Bootgly\ABI\BOOTSTRAP_FILENAME;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


return new Test(
   description: 'Shipped examples are stocked once, unbooted, with the binding their platform registers',
   test: function () {
      // ? proc_open unavailable — nothing to spawn
      if (function_exists('proc_open') === false) {
         yield assert(assertion: true, description: 'Skipped: proc_open is unavailable');
         return;
      }
      // ? Nested probe guard
      if (getenv('BOOTGLY_TEST_STOCK_PROBE') === '1') {
         yield assert(assertion: true, description: 'Skipped: nested stock probe');
         return;
      }

      // ! Human environment — agent markers would force the JSON contract
      $environment = getenv();
      foreach ([
         'AI_AGENT', 'AMP_CURRENT_THREAD_ID', 'ANTIGRAVITY_AGENT',
         'AUGMENT_AGENT', 'CLAUDECODE', 'CLAUDE_CODE', 'CODEX_SANDBOX',
         'CODEX_THREAD_ID', 'COPILOT_CLI', 'CURSOR_AGENT', 'GEMINI_CLI',
         'OPENCODE', 'OPENCODE_CLIENT', 'REPL_ID',
         'BOOTGLY_AGENT_STDOUT_REDIRECTED', 'BOOTGLY_TTY',
      ] as $variable) {
         unset($environment[$variable]);
      }
      $environment['BOOTGLY_TEST_STOCK_PROBE'] = '1';

      // ! Runner — the kit root is the working directory
      $run = static function (array $arguments, array $environment, string $cwd): array {
         $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
         ];

         $Process = proc_open(
            [PHP_BINARY, ...$arguments],
            $descriptors,
            $pipes,
            $cwd,
            $environment
         );
         if (is_resource($Process) === false) {
            return [-1, ''];
         }

         /** @var array<int,resource> $pipes */
         $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
         fclose($pipes[1]);
         fclose($pipes[2]);
         $status = proc_close($Process);

         return [$status, $output];
      };
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

      // ! Fixture — a kit whose Web platform ships one exportable project
      //   registered as WPI; the core ships its Demos through the same path.
      //   No `.gitmodules`: the fresh boot is the only stock trigger here.
      $directory = Temporaries::reserve('project-stock');
      $entry = "{$directory}/bootgly";
      $root = BOOTGLY_ROOT_DIR;
      $registry = "{$directory}/projects/Bootgly.projects.php";
      $files = [
         $entry => "<?php\n"
            . "define('BOOTGLY_WORKING_BASE', __DIR__);\n"
            . "define('BOOTGLY_WORKING_DIR', BOOTGLY_WORKING_BASE . DIRECTORY_SEPARATOR);\n"
            . "(include '{$root}autoboot.php') || exit(1);\n",
         "{$directory}/.gitmodules" => "[submodule \"Web\"]\n"
            . "\tpath = Web\n"
            . "\turl = https://example.invalid/web.git\n",
         "{$directory}/Web/" . BOOTSTRAP_FILENAME => "<?php\n\nreturn true;\n",
         "{$directory}/Web/projects/Bootgly.projects.php" => "<?php\n\n"
            . "return [\n"
            . "   'Fake' => ['interfaces' => ['WPI'], 'default' => true],\n"
            . "];\n",
         "{$directory}/Web/projects/Fake/Fake.Project.php" => "<?php\n\n"
            . "use Bootgly\\API\\Projects\\Project;\n\n"
            . "return new Project(\n"
            . "   name: 'Fake',\n"
            . "   description: 'Stock probe',\n"
            . "   version: '1.0.0',\n"
            . "   author: 'Probe',\n"
            . "   exportable: true,\n"
            . "   boot: static function (): void {}\n"
            . ");\n",
      ];
      // ! The platform is explicit on every run: `none` keeps the fresh-boot
      //   trigger alone, `web` asks for that platform (and its examples)
      $create = static fn (string $name, string $platform = 'none'): array => [
         $entry, 'projects', 'create', $name,
         '--from=scratch', '--interfaces=CLI', '--yes', '--no-git', "--platform={$platform}",
      ];

      try {
         mkdir("{$directory}/Web/projects/Fake", 0o700, true);
         foreach ($files as $file => $contents) {
            file_put_contents($file, $contents);
         }

         // @ The first create on a fresh kit stocks the examples of every
         //   platform present
         [$status, $output] = $run($create('App'), $environment, $directory);
         $Registry = is_file($registry) ? (array) (include $registry) : [];
         yield assert(
            assertion: $status === 0
               && str_contains($output, 'shipped example projects')
               && is_file("{$directory}/projects/Fake/Fake.Project.php")
               && is_file("{$directory}/projects/Demo/CLI/CLI.Project.php"),
            description: 'the first create on a fresh kit imports the shipped examples'
               . " (status {$status})"
         );

         // @ Each example keeps the binding ITS platform registers — not the
         //   core's, not the option given to the created project — and a legacy
         //   default flag in the platform registry is never propagated
         yield assert(
            assertion: ($Registry['Fake']['interfaces'] ?? null) === ['WPI']
               && ($Registry['Demo/HTTP_Server_CLI']['interfaces'] ?? null) === ['WPI']
               && ($Registry['Demo/CLI']['interfaces'] ?? null) === ['CLI']
               && ($Registry['App']['interfaces'] ?? null) === ['CLI']
               && isset($Registry['Fake']['default']) === false,
            description: 'an example is registered with the interfaces of the platform that ships it'
         );

         // @ Examples arrive unbooted — adoption is explicit (`project <Name> boot`)
         yield assert(
            assertion: is_dir("{$directory}/projects/Fake/.git") === false
               && is_dir("{$directory}/projects/Demo/CLI/.git") === false,
            description: 'examples arrive without a repository of their own'
         );

         // @ A prepared kit stocks nothing more: an existing copy is the
         //   user's (edits kept) and a deleted one stays deleted
         file_put_contents("{$directory}/projects/Fake/mine.txt", "kept\n");
         $erase("{$directory}/projects/Demo/CLI");
         [$status, $output] = $run($create('Second'), $environment, $directory);
         yield assert(
            assertion: $status === 0
               && str_contains($output, 'shipped example projects') === false
               && is_file("{$directory}/projects/Fake/mine.txt")
               && is_dir("{$directory}/projects/Demo/CLI") === false,
            description: 'a prepared kit imports nothing more — user copies are kept, deleted examples stay deleted'
               . " (status {$status})"
         );

         // @ The platform trigger reaches the guard the fresh-boot triggers
         //   never do: an explicit `--platform=web` on a prepared kit stocks
         //   that platform's set, and an existing copy — the user's, edits
         //   included — is skipped, never replaced
         [$status, $output] = $run($create('Third', 'web'), $environment, $directory);
         yield assert(
            assertion: $status === 0
               && is_file("{$directory}/projects/Fake/mine.txt")
               && (string) file_get_contents("{$directory}/projects/Fake/mine.txt") === "kept\n",
            description: 'an explicit --platform stocks its set without touching an existing copy'
               . " (status {$status})"
         );

         // @ ...and it is the only way back: an example the user deleted
         //   returns when that platform is asked for again
         $erase("{$directory}/projects/Fake");
         [$status, $output] = $run($create('Fourth', 'web'), $environment, $directory);
         yield assert(
            assertion: $status === 0
               && is_file("{$directory}/projects/Fake/Fake.Project.php")
               && is_file("{$directory}/projects/Fake/mine.txt") === false,
            description: 'asking for the platform again restocks the example the user deleted'
               . " (status {$status})"
         );
      }
      finally {
         $erase($directory);
      }
   }
);
