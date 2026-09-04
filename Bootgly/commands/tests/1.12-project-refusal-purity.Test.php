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
use function json_encode;
use function mkdir;
use function proc_close;
use function proc_open;
use function rmdir;
use function scandir;
use function str_contains;
use function stream_get_contents;
use function unlink;
use RuntimeException;

use const Bootgly\ABI\BOOTSTRAP_FILENAME;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


return new Test(
   description: 'A refused create never bootstraps the kit it was refused on',
   test: function () {
      // ? proc_open unavailable — nothing to spawn
      if (function_exists('proc_open') === false) {
         yield assert(assertion: true, description: 'Skipped: proc_open is unavailable');
         return;
      }
      // ? Nested probe guard
      if (getenv('BOOTGLY_TEST_REFUSAL_PROBE') === '1') {
         yield assert(assertion: true, description: 'Skipped: nested refusal probe');
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
      $environment['BOOTGLY_TEST_REFUSAL_PROBE'] = '1';
      // ! Width is an ASSERTION INPUT: `Alert` clips its message to the
      //   terminal size, and `COLUMNS` is the first source `Screen` reads. A
      //   narrow host would cut the refusal text these cases match on and
      //   report a shelf defect that did not happen.
      $environment['COLUMNS'] = '200';

      // ! Runner — the kit root is the working directory
      $run = static function (array $arguments, array $environment, string $cwd): array {
         $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
         ];

         $process = proc_open(
            [PHP_BINARY, ...$arguments],
            $descriptors,
            $pipes,
            $cwd,
            $environment
         );
         // ? A child that never started would make the refusal loop below
         //   trivially true (no status 0, nothing bootstrapped), so it must be
         //   a failure of the harness, never a silent pass
         if (is_resource($process) === false) {
            throw new RuntimeException('The probe could not spawn a PHP child process.');
         }

         /** @var array<int,resource> $pipes */
         $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
         fclose($pipes[1]);
         fclose($pipes[2]);
         $status = proc_close($process);

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

      // ! Fixture — a FRESH kit, the state every `docker run bootgly/bootgly.kit`
      //   starts in: an entry point that names the directory the working one, no
      //   `projects/` and no registry, and a Web platform shipping one exportable
      //   example (the core ships its Demos through the same path). No
      //   `.gitmodules`: the fresh boot is the only stock trigger here, and the
      //   `--platform` value is judged on that layout too.
      $build = static function (string $directory): string {
         $entry = "{$directory}/bootgly";
         $root = BOOTGLY_ROOT_DIR;
         $files = [
            $entry => "<?php\n"
               . "define('BOOTGLY_WORKING_BASE', __DIR__);\n"
               . "define('BOOTGLY_WORKING_DIR', BOOTGLY_WORKING_BASE . DIRECTORY_SEPARATOR);\n"
               . "(include '{$root}autoboot.php') || exit(1);\n",
            "{$directory}/Web/" . BOOTSTRAP_FILENAME => "<?php\n\nreturn true;\n",
            "{$directory}/Web/projects/Bootgly.projects.php" => "<?php\n\n"
               . "return [\n"
               . "   'Fake' => ['interfaces' => ['WPI']],\n"
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

         mkdir("{$directory}/Web/projects/Fake", 0o700, true);
         foreach ($files as $file => $contents) {
            file_put_contents($file, $contents);
         }

         return $entry;
      };
      // ! The witnesses a kit bootstrap leaves behind: the resource directories,
      //   the shipped examples and the registry that allow-lists them
      $bootstrapped = static fn (string $directory): bool =>
         is_file("{$directory}/projects/Bootgly.projects.php") === true
         || is_dir("{$directory}/projects/Demo") === true
         || is_dir("{$directory}/projects/Fake") === true
         // ! The resource directories too, in the order `kit boot` creates
         //   them: a bootstrap that stopped after the first one would
         //   otherwise read as pure
         || is_dir("{$directory}/scripts") === true
         || is_dir("{$directory}/storage") === true;

      $directory = Temporaries::reserve('project-refusal');
      $reserved = Temporaries::reserve('project-reservation');
      $shelf = Temporaries::reserve('project-import-shelf');

      try {
         $entry = $build($directory);

         // @ Every refusal shape, each judged against a kit that is still
         //   fresh: a command that was never going to proceed must not lay
         //   down a workspace — and the allow-list registry least of all — on
         //   its way out. A shape that DOES bootstrap has its witnesses erased
         //   so the next shape is judged on a fresh kit too.
         $refusals = [
            'a non-numeric --port' => ['arguments' => [
               'projects', 'create', 'PortProbe',
               '--yes', '--platform=none', '--interfaces=WPI', '--port=not-a-port',
            ], 'says' => 'Invalid port'],
            'an out-of-range --port' => ['arguments' => [
               'projects', 'create', 'PortProbe',
               '--yes', '--platform=none', '--interfaces=WPI', '--port=65536',
            ], 'says' => 'Invalid port'],
            'an invalid --interfaces' => ['arguments' => [
               'projects', 'create', 'IfaceProbe',
               '--yes', '--platform=none', '--interfaces=XPI',
            ], 'says' => 'Invalid interface'],
            'an invalid --platform' => ['arguments' => [
               'projects', 'create', 'PlatProbe',
               '--yes', '--platform=bogus', '--interfaces=CLI',
            ], 'says' => 'Invalid platform'],
            'a quoted project path' => ['arguments' => [
               'projects', 'create', "Bad'Path",
               '--yes', '--platform=none', '--interfaces=CLI',
            ], 'says' => 'Invalid project path'],
            'a reserved namespace root' => ['arguments' => [
               'projects', 'create', 'Bootgly',
               '--yes', '--platform=none', '--interfaces=CLI',
            ], 'says' => 'is a reserved Bootgly'],
            'a control character in --description' => ['arguments' => [
               'projects', 'create', 'CtrlProbe',
               '--yes', '--platform=none', '--interfaces=CLI', "--description=bad\x01desc",
            ], 'says' => 'control characters are not allowed'],
            // ! The shelf the examples are stocked FROM: refusing this path
            //   after the reservation would cost a fresh kit that example for
            //   good, since `stock()` fires once
            'a name the framework ships' => ['arguments' => [
               'projects', 'create', 'Demo/CLI',
               '--yes', '--platform=none', '--interfaces=CLI',
            ], 'says' => 'already exists'],
            // ! `none` is exclusive — one rule, read by the entry gate and by
            //   `prepare()`; two copies had already drifted apart here
            'a --platform list mixing none with a platform' => ['arguments' => [
               'projects', 'create', 'MixProbe',
               '--yes', '--platform=none,web', '--interfaces=CLI',
            ], 'says' => 'cannot be combined'],
            'a missing project path' => ['arguments' => [
               'projects', 'create',
               '--yes', '--platform=none', '--interfaces=CLI',
            ], 'says' => 'Missing project path'],
            'a quoted import target' => ['arguments' => [
               'projects', 'import', 'https://example.invalid/probe.git', "Bad'Name",
               '--yes', '--platform=none',
            ], 'says' => 'Invalid project path'],
         ];

         $built = [];
         $mute = [];
         foreach ($refusals as $label => $refusal) {
            [$status, $said] = $run([$entry, ...$refusal['arguments']], $environment, $directory);

            if ($status === 0 || $bootstrapped($directory) === true) {
               $built[] = $label;

               $erase("{$directory}/projects");
               $erase("{$directory}/scripts");
               $erase("{$directory}/storage");
            }

            // ? The refusal must also SAY which rule it applied: a swapped or
            //   mangled message is how a user learns the wrong thing, and a
            //   status alone cannot tell a clean refusal from a fatal error
            if (str_contains($said, $refusal['says']) === false) {
               $mute[] = $label;
            }
         }
         // @ A refusal is the DETERMINISTIC path for a hostile name — `vet()`
         //   rejects it every time — so the message that names it must not hand
         //   the terminal what it refused. `@#red:` has to arrive as text, and
         //   the OSC introducer (ESC `]`, which the CLI's own colours never
         //   emit — those are ESC `[`) must not arrive at all.
         $payload = "Ev@#red:il\x1b]0;PWNED\x07X";
         [, $said] = $run(
            [$entry, 'projects', 'create', $payload, '--yes', '--platform=none', '--interfaces=CLI'],
            $environment,
            $directory
         );
         $erase("{$directory}/projects");
         $erase("{$directory}/scripts");
         $erase("{$directory}/storage");

         yield assert(
            assertion: str_contains($said, '#red:') === true
               && str_contains($said, "\x1b]") === false,
            description: 'a refusal renders a hostile path as text, never as markup or an escape'
         );

         // @ ...and the scrub must not overshoot: `git@host:owner/repo.git` is
         //   the mainstream SSH clone URL, and the `@` in it opens no
         //   directive. Deleting it would show a URL that does not exist —
         //   in the message a user copies out to retry. `GIT_SSH_COMMAND=false`
         //   makes the clone fail instantly, with no network and no DNS.
         $offline = $environment;
         $offline['GIT_SSH_COMMAND'] = 'false';
         $SSH = 'git@example.invalid:owner/repo.git';
         [, $said] = $run(
            [$entry, 'projects', 'import', $SSH, 'SSHProbe', '--yes', '--platform=none'],
            $offline,
            $directory
         );
         $erase("{$directory}/projects");
         $erase("{$directory}/scripts");
         $erase("{$directory}/storage");

         yield assert(
            assertion: str_contains($said, $SSH) === true,
            description: 'an SSH clone URL keeps its `@` in the message that names it'
         );

         yield assert(
            assertion: $mute === [],
            description: 'each refusal names the rule it applied, silent or wrong for: '
               . json_encode($mute)
         );
         yield assert(
            assertion: $built === [],
            description: 'a refused create leaves the kit unbootstrapped, bootstrapped by: '
               . json_encode($built)
         );

         // @ ...and the kit IS still fresh, so the first create that proceeds
         //   is what bootstraps it: resource directories, the shipped examples
         //   of every platform present, and the registry that allow-lists them
         [$status, $output] = $run(
            [
               $entry, 'projects', 'create', 'App',
               '--from=scratch', '--interfaces=CLI', '--yes', '--no-git', '--platform=none',
            ],
            $environment,
            $directory
         );
         /** @var array<string, array{interfaces?: array<string>}> $registry */
         $registry = is_file("{$directory}/projects/Bootgly.projects.php") === true
            ? (array) (include "{$directory}/projects/Bootgly.projects.php")
            : [];
         yield assert(
            assertion: $status === 0
               && str_contains($output, 'shipped example projects') === true
               && is_dir("{$directory}/storage") === true
               && is_file("{$directory}/projects/App/App.Project.php") === true
               && is_file("{$directory}/projects/Fake/Fake.Project.php") === true
               && is_file("{$directory}/projects/Demo/CLI/CLI.Project.php") === true
               && ($registry['App']['interfaces'] ?? null) === ['CLI'],
            description: 'a valid create still bootstraps the kit it needed'
               . " (status {$status})"
         );

         // @ The reservation the ordering exists for is untouched: an example
         //   stocked by this very run must never claim the path the user asked
         //   for. `Fake` is what the Web platform ships AND what the user asks
         //   for — the user's copy wins, and the rest of the shelf still lands
         $second = $build($reserved);
         [$status, ] = $run(
            [
               $second, 'projects', 'create', 'Fake',
               '--from=scratch', '--interfaces=CLI', '--yes', '--no-git', '--platform=none',
               '--description=ReservedByTheUser',
            ],
            $environment,
            $reserved
         );
         /** @var array<string, array{interfaces?: array<string>}> $claimed */
         $claimed = is_file("{$reserved}/projects/Bootgly.projects.php") === true
            ? (array) (include "{$reserved}/projects/Bootgly.projects.php")
            : [];
         $signature = is_file("{$reserved}/projects/Fake/Fake.Project.php") === true
            ? (string) file_get_contents("{$reserved}/projects/Fake/Fake.Project.php")
            : '';
         yield assert(
            assertion: $status === 0
               && ($claimed['Fake']['interfaces'] ?? null) === ['CLI']
               && str_contains($signature, 'ReservedByTheUser') === true
               && str_contains($signature, 'Stock probe') === false
               && is_file("{$reserved}/projects/Demo/CLI/CLI.Project.php") === true,
            description: 'a stocked example never claims the path the run reserved'
               . " (status {$status})"
         );

         // @ An import that does NOT complete must leave the shelf whole. It
         //   takes no reservation, unlike create(): a name already shipped is
         //   refused, never claimed — because `stock()` fires once on a fresh
         //   kit, so a name reserved for an import that then fails is a
         //   shipped example gone for good, with nothing to put it back.
         $third = $build($shelf);
         [$status, $output] = $run(
            [
               $third, 'projects', 'import',
               'https://invalid.invalid/nope.git', 'Fake', '--yes',
            ],
            $environment,
            $shelf
         );
         /** @var array<string, array{interfaces?: array<string>}> $shelved */
         $shelved = is_file("{$shelf}/projects/Bootgly.projects.php") === true
            ? (array) (include "{$shelf}/projects/Bootgly.projects.php")
            : [];

         yield assert(
            assertion: $status !== 0
               && str_contains($output, 'already registered') === true
               && is_file("{$shelf}/projects/Fake/Fake.Project.php") === true
               && ($shelved['Fake']['interfaces'] ?? null) === ['WPI'],
            description: 'a refused import leaves the shipped example on the shelf'
               . " (status {$status})"
         );
      }
      finally {
         $erase($directory);
         $erase($reserved);
         $erase($shelf);
      }
   }
);
