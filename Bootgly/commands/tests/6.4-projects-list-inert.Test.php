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
use function array_merge;
use function array_values;
use function assert;
use function bin2hex;
use function dirname;
use function escapeshellarg;
use function exec;
use function fclose;
use function file_exists;
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
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function proc_close;
use function proc_open;
use function random_bytes;
use function rmdir;
use function scandir;
use function str_contains;
use function stream_get_contents;
use function symlink;
use function unlink;
use function var_export;
use RuntimeException;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;
use Bootgly\API\Environment\Agent;


return new Test(
   description: '`projects list` / `project <x> info` read signatures without running them; hostile metadata and symlinked signatures are inert',
   test: function () {
      // ? proc_open unavailable — nothing to spawn
      if (function_exists('proc_open') === false) {
         yield assert(assertion: true, description: 'Skipped: proc_open is unavailable');
         return;
      }

      // ! Human environment — agent markers would force the JSON contract
      $environment = getenv();
      foreach ([...array_merge(...array_values(Agent::MARKERS)), 'AI_AGENT', 'BOOTGLY_AGENT_STDOUT_REDIRECTED', 'BOOTGLY_TTY'] as $variable) {
         unset($environment[$variable]);
      }
      // ! Width is an assertion input (no clip of the rows matched below);
      //   git speaks English so no message depends on the host locale
      $environment['COLUMNS'] = '400';
      $environment['LC_ALL'] = 'C';
      // ! The value a DYNAMIC metadata expression would read — it must never
      //   reach the listing (proof that the expression was not evaluated)
      $nonce = bin2hex(random_bytes(6));
      $environment['BOOTGLY_TEST_DYNAMIC_PROBE'] = "DYNAMIC-{$nonce}";

      // ! Runner — the kit root is the working directory, stdin is closed (a
      //   confirmation reads EOF and takes its default: "no")
      $run = static function (array $arguments, array $environment, string $cwd): array {
         $process = proc_open(
            [PHP_BINARY, '-d', 'opcache.jit=0', ...$arguments],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd,
            $environment
         );
         // ? A child that never started would make every "nothing ran" leg true
         if (is_resource($process) === false) {
            throw new RuntimeException('The probe could not spawn a PHP child process.');
         }

         /** @var array<int,resource> $pipes */
         $stdout = (string) stream_get_contents($pipes[1]);
         $stderr = (string) stream_get_contents($pipes[2]);
         $output = "{$stdout}{$stderr}";
         fclose($pipes[1]);
         fclose($pipes[2]);

         return [proc_close($process), $output];
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
      $write = static function (string $file, string $contents): void {
         $dir = dirname($file);
         if (is_dir($dir) === false) {
            mkdir($dir, 0o700, true);
         }
         file_put_contents($file, $contents);
      };
      // ! One signature — `$arguments` is PHP source for the named arguments
      $sign = static fn (string $prelude, string $arguments): string => <<<PHP
      <?php

      use Bootgly\\API\\Projects\\Project;

      {$prelude}return new Project(
      {$arguments},
         exportable: false,
         boot: static function (): void {}
      );

      PHP;

      $directory = Temporaries::reserve('projects-list-inert');
      $outside = Temporaries::reserve('projects-list-outside');
      $importing = Temporaries::reserve('projects-list-import');

      try {
         // ! Fixture kit — an entry point naming the directory the working one,
         //   and a registry of hostile signatures. Every signature that RUNS
         //   leaves a witness file beside the kit.
         $root = BOOTGLY_ROOT_DIR;
         $witness = "{$outside}/EXECUTED";
         $host = "{$outside}/host-file";
         file_put_contents($host, "HOSTFILE-{$nonce}\n");
         $control = "Clear\x1b[2Jscreen \x1b]52;c;cHduZWQ=\x07 \xc2\x9b31mC1 @#red:markup@; end";

         $write("{$directory}/bootgly", <<<PHP
         <?php
         define('BOOTGLY_WORKING_BASE', __DIR__);
         define('BOOTGLY_WORKING_DIR', BOOTGLY_WORKING_BASE . DIRECTORY_SEPARATOR);
         (include '{$root}autoboot.php') || exit(1);

         PHP);
         // ! `Broken` LAST: the registry order is the reading order, so every
         //   other signature is reached before the one that throws
         $write("{$directory}/projects/Bootgly.projects.php", <<<'PHP'
         <?php

         return [
            'Dynamic' => ['interfaces' => ['CLI']],
            'Escape' => ['interfaces' => ['WPI']],
            'Evil' => ['interfaces' => ['CLI']],
            'Linked' => ['interfaces' => ['CLI']],
            'Dangling' => ['interfaces' => ['CLI']],
            'Odd' => ['interfaces' => ['CLI']],
            'Broken' => ['interfaces' => ['CLI']],
         ];

         PHP);
         // # Top-level code with a side effect, literal metadata
         $witnessed = var_export($witness, true);
         $write("{$directory}/projects/Evil/Evil.Project.php", $sign(
            "file_put_contents({$witnessed}, 'ran', FILE_APPEND);\n",
            "   name: 'Evil', description: 'Looks innocent', version: '1.0.0', author: 'Mallory'"
         ));
         // # Hostile literal metadata: CSI erase, OSC 52, C1 CSI, Output markup
         $controlled = var_export($control, true);
         $write("{$directory}/projects/Escape/Escape.Project.php", $sign(
            '',
            "   name: {$controlled}, description: {$controlled}, version: {$controlled}, author: {$controlled}"
         ));
         // # A metadata expression — evaluated only by an executing path
         $write("{$directory}/projects/Dynamic/Dynamic.Project.php", $sign(
            '',
            "   name: 'Dynamic', description: (string) getenv('BOOTGLY_TEST_DYNAMIC_PROBE'), version: '1.0', author: 'D'"
         ));
         // # Not a Project at all — printed and listed silently at HEAD
         $write("{$directory}/projects/Odd/Odd.Project.php", "<?php\n\necho 'ODD-{$nonce}';\n\nreturn ['not' => 'a project'];\n");
         // # Throws at the top level — aborted the WHOLE listing at HEAD
         $write("{$directory}/projects/Broken/Broken.Project.php", "<?php\n\nthrow new \\RuntimeException('BROKEN-{$nonce}');\n");
         // # A symlinked signature pointing at a VALID signature outside the
         //   kit: only the symlink guard keeps its metadata out of the listing
         $write("{$outside}/Elsewhere.Project.php", $sign('', "   name: 'Elsewhere', description: 'LINKED-{$nonce}'"));
         mkdir("{$directory}/projects/Linked", 0o700, true);
         symlink("{$outside}/Elsewhere.Project.php", "{$directory}/projects/Linked/Linked.Project.php");
         // # A dangling symlinked signature — a row too, never a silent gap
         mkdir("{$directory}/projects/Dangling", 0o700, true);
         symlink("{$outside}/nowhere.Project.php", "{$directory}/projects/Dangling/Dangling.Project.php");

         // @ `projects list`
         [$status, $listed] = $run(["{$directory}/bootgly", 'projects', 'list'], $environment, $directory);
         $ran = file_exists($witness);
         $rows = preg_match_all('/^║ \d+ /mu', $listed);

         // # Leg 1 — reading is not running
         yield assert(
            assertion: $ran === false
               && str_contains($listed, "ODD-{$nonce}") === false
               && str_contains($listed, "LINKED-{$nonce}") === false,
            description: '`projects list` runs no signature: no witness file, no top-level echo, nothing read through a symlink'
         );

         // # Leg 2 — one bad signature is one marked row, never an aborted listing
         yield assert(
            assertion: $status === 0 && $rows === 7
               && str_contains($listed, "BROKEN-{$nonce}") === false
               && preg_match('/│ Broken +│ CLI +│ \(invalid signature/u', $listed) === 1
               && preg_match('/│ Odd +│ CLI +│ \(invalid signature/u', $listed) === 1
               && preg_match('/│ Linked +│ CLI +│ \(invalid signature: symlinked signature refused/u', $listed) === 1
               && preg_match('/│ Dangling +│ CLI +│ \(invalid signature: symlinked signature refused/u', $listed) === 1,
            description: "every registered project is listed (7 rows, got {$rows}, exit {$status}); a throwing, non-Project, symlinked or dangling signature is a row with its error marker"
         );

         // # Leg 3 — literal metadata survives; an expression is shown as dynamic, never evaluated
         yield assert(
            assertion: str_contains($listed, 'Looks innocent')
               && str_contains($listed, "DYNAMIC-{$nonce}") === false
               && preg_match('/│ Dynamic +│ CLI +│ \(dynamic\)/u', $listed) === 1,
            description: 'a literal description is listed as written; a non-literal one reads "(dynamic)"'
         );

         // # Leg 4 — metadata cannot drive the terminal
         yield assert(
            assertion: str_contains($listed, "\x1b[2J") === false
               && str_contains($listed, "\x1b]") === false
               && str_contains($listed, "\xc2\x9b") === false
               && str_contains($listed, 'Clear[2Jscreen'),
            description: 'control characters in a description never reach the table raw (the text around them stays)'
         );

         // @ `project <x> info` — its own signature, and the others it used to discover
         [$status, $shown] = $run(["{$directory}/bootgly", 'project', 'Escape', 'info'], $environment, $directory);
         [, $evil] = $run(["{$directory}/bootgly", 'project', 'Evil', 'info'], $environment, $directory);
         [$broke, $broken] = $run(["{$directory}/bootgly", 'project', 'Broken', 'info'], $environment, $directory);

         // # Leg 5 — info is inert and neutral too (its Fieldset renders markup)
         yield assert(
            assertion: $status === 0
               && file_exists($witness) === false
               && str_contains($evil, 'Looks innocent')
               && str_contains($shown, "\x1b[2J") === false
               && str_contains($shown, "\x1b]") === false
               && str_contains($shown, "\xc2\x9b") === false
               && str_contains($shown, "\x1b[31mmarkup") === false
               && str_contains($shown, "ODD-{$nonce}") === false
               && preg_match('/Interfaces +.*WPI/u', $shown) === 1
               && $broke === 0
               && str_contains($broken, 'Signature')
               && str_contains($broken, 'invalid')
               && str_contains($broken, "BROKEN-{$nonce}") === false,
            description: '`project <x> info` runs no signature (its own nor the others), renders every metadata row as text, reads interfaces from the registry and marks an invalid signature'
         );

         // @ Import — a git repository whose root signature is a symbolic link
         $environment['GIT_CONFIG_NOSYSTEM'] = '1';
         $environment['GIT_CONFIG_GLOBAL'] = '/dev/null';
         $kit = "{$importing}/kit";
         $repository = "{$importing}/repository";
         $write("{$kit}/bootgly", (string) file_get_contents("{$directory}/bootgly"));
         mkdir($repository, 0o700, true);
         symlink($host, "{$repository}/Shop.Project.php");
         $quoted = escapeshellarg($repository);
         $git = "git -C {$quoted}";
         $identity = '-c user.name=probe -c user.email=probe@example.invalid -c commit.gpgsign=false';
         exec("{$git} init -q 2>&1 && {$git} add -A 2>&1 && {$git} {$identity} commit -qm init 2>&1", $lines, $code);
         yield assert(
            assertion: $code === 0,
            description: 'probe precondition: the fixture repository commits (git is available)'
         );

         [$status, $said] = $run(
            ["{$kit}/bootgly", 'projects', 'import', "file://{$repository}", 'Shop', '--yes', '--platform=none', '--interfaces=CLI'],
            $environment,
            $kit
         );
         $registry = is_file("{$kit}/projects/Bootgly.projects.php")
            ? (string) file_get_contents("{$kit}/projects/Bootgly.projects.php")
            : '';

         // # Leg 6 — the symlinked signature is refused, with its reason, leaving nothing behind
         yield assert(
            assertion: $status !== 0
               && str_contains($said, 'symbolic link')
               && file_exists("{$kit}/projects/Shop") === false
               && str_contains($registry, "'Shop'") === false,
            description: "a symlinked signature is refused by the import (exit {$status}), and neither the copy nor a registry entry is left"
         );

         // @ Import — a regular signature, consent answered by EOF ("no")
         unlink("{$repository}/Shop.Project.php");
         file_put_contents("{$repository}/Shop.Project.php", $sign('', "   name: 'Shop'"));
         exec("{$git} add -A 2>&1 && {$git} {$identity} commit -qm regular 2>&1");
         [, $asked] = $run(
            // ! Another target name: at HEAD the refused leg above imports
            //   `Shop`, and a collision would fail this leg for the wrong reason
            ["{$kit}/bootgly", 'projects', 'import', "file://{$repository}", 'Store', '--platform=none', '--interfaces=CLI'],
            $environment,
            $kit
         );

         // # Leg 7 — the consent names every command that executes the signature
         $question = preg_match('/❯ (.*)\[y\/N\]/u', (string) preg_replace('/\x1b\[[0-9;]*m/', '', $asked), $match) === 1 ? $match[1] : '';
         $prompt = json_encode($question);
         yield assert(
            assertion: str_contains($asked, 'Import aborted.')
               && str_contains($question, 'start')
               && str_contains($question, 'migrate')
               && str_contains($question, 'seed')
               && str_contains($question, 'schedule')
               && str_contains($question, 'tests')
               && str_contains($question, 'only read'),
            description: "the import consent names start, migrate, seed, schedule and the tests, and says list/info only read the signature — got: {$prompt}"
         );

         // @ Import — a repository carrying a `bootgly` launcher in a subfolder
         $write("{$repository}/tools/bootgly", "<?php
define('BOOTGLY_WORKING_BASE', __DIR__);
");
         exec("{$git} add -A 2>&1 && {$git} {$identity} commit -qm launcher 2>&1");
         [$status, $said] = $run(
            ["{$kit}/bootgly", 'projects', 'import', "file://{$repository}", 'Launched', '--yes', '--platform=none', '--interfaces=CLI'],
            $environment,
            $kit
         );

         // # Leg 8 — a launcher the global command would run from inside the project is refused
         yield assert(
            assertion: $status !== 0
               && str_contains($said, 'tools/bootgly')
               && str_contains($said, 'launcher')
               && file_exists("{$kit}/projects/Launched") === false,
            description: "a repository carrying a `bootgly` launcher is refused by the import (exit {$status}), leaving nothing behind"
         );

         // @ The `projects create` picker reads `exportable` as tokens too
         $picker = Temporaries::reserve('projects-list-picker');
         $write("{$picker}/Literal/Literal.Project.php", (string) preg_replace(
            '/exportable: false/',
            'exportable: true',
            $sign("file_put_contents({$witnessed}, 'picked', FILE_APPEND);\n", "   name: 'Literal'")
         ));
         $write("{$picker}/Computed/Computed.Project.php", (string) preg_replace(
            '/exportable: false/',
            "exportable: getenv('BOOTGLY_TEST_DYNAMIC_PROBE') !== false",
            $sign("file_put_contents({$witnessed}, 'picked', FILE_APPEND);\n", "   name: 'Computed'")
         ));
         $inspect = fn (string $dir): bool => $this->inspect($dir);
         $Command = new ProjectsCommand;
         $literal = $inspect->call($Command, "{$picker}/Literal");
         $computed = $inspect->call($Command, "{$picker}/Computed");
         $erase($picker);

         // # Leg 9 — the picker offers a literal `exportable: true`, runs nothing, and a computed one is not offered
         yield assert(
            assertion: $literal === true && $computed === false && file_exists($witness) === false,
            description: 'the create picker reads `exportable` without running the signature (a literal true is offered, an expression is not)'
         );
      }
      finally {
         $erase($directory);
         $erase($outside);
         $erase($importing);
      }
   }
);
