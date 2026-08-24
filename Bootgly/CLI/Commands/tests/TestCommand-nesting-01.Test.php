<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI;


use const BOOTGLY_ROOT_DIR;
use function assert;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function function_exists;
use function getenv;
use function is_file;
use function is_resource;
use function mkdir;
use function proc_close;
use function proc_open;
use function rmdir;
use function str_contains;
use function stream_get_contents;
use function substr_count;
use function unlink;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


return new Test(
   description: 'A suite that re-execs the runner is bounded — a nested run never forks the machine',
   test: function () {
      // ? proc_open unavailable — nothing to spawn
      if (function_exists('proc_open') === false) {
         yield assert(assertion: true, description: 'Skipped: proc_open is unavailable');
         return;
      }
      // ? Nested probe guard
      if (getenv('BOOTGLY_TEST_NESTING_PROBE') === '1') {
         yield assert(assertion: true, description: 'Skipped: nested nesting probe');
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
      $environment['BOOTGLY_TEST_NESTING_PROBE'] = '1';
      // ! The chain starts fresh: this process is itself a run, and the
      //   fixture must be able to reach the limit on its own
      unset($environment['BOOTGLY_TEST_DEPTH']);

      $run = static function (array $arguments, array $environment, string $cwd): array {
         $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
         ];

         $Process = proc_open([PHP_BINARY, ...$arguments], $descriptors, $pipes, $cwd, $environment);
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

      // ! Fixture — a registered project whose suite re-execs `bootgly test`
      //   with the index it just resolved: the working directory, the scope
      //   and the index are identical in the child, so nothing but the
      //   runner's own generation limit ends the chain.
      $directory = Temporaries::reserve('testcommand-nesting');
      $entry = "{$directory}/bootgly";
      $ledger = "{$directory}/generations.log";
      $root = BOOTGLY_ROOT_DIR;
      $files = [
         $entry => "<?php\n"
            . "define('BOOTGLY_WORKING_BASE', __DIR__);\n"
            . "define('BOOTGLY_WORKING_DIR', BOOTGLY_WORKING_BASE . DIRECTORY_SEPARATOR);\n"
            . "(include '{$root}autoboot.php') || exit(1);\n",
         "{$directory}/projects/Bootgly.projects.php" => "<?php\n\n"
            . "return ['Loop' => ['interfaces' => ['CLI']]];\n",
         "{$directory}/projects/Loop/tests/autoboot.php" => "<?php\n\n"
            . "use Bootgly\\ACI\\Tests\\Suites;\n\n"
            . "return new Suites(directories: ['tests/E2E/']);\n",
         "{$directory}/projects/Loop/tests/E2E/autoboot.php" => "<?php\n\n"
            . "use Bootgly\\ACI\\Tests\\Suite;\n\n"
            . "return new Suite(\n"
            . "   autoBoot: function (Suite|null \$Suite = null): true {\n"
            . "      file_put_contents('{$ledger}', getmypid() . \"\\n\", FILE_APPEND);\n"
            . "      exec(PHP_BINARY . ' ' . escapeshellarg('{$entry}') . ' test 1 2>&1', \$output);\n"
            . "      file_put_contents('{$ledger}.out', implode(\"\\n\", \$output) . \"\\n\", FILE_APPEND);\n\n"
            . "      return true;\n"
            . "   },\n"
            . "   autoInstance: false,\n"
            . "   autoReport: false,\n"
            . "   autoSummarize: false,\n"
            . "   suiteName: 'Loop',\n"
            . "   tests: []\n"
            . ");\n",
      ];

      try {
         mkdir("{$directory}/projects/Loop/tests/E2E", 0o700, true);
         foreach ($files as $file => $contents) {
            file_put_contents($file, $contents);
         }

         [$status, $output] = $run([$entry, 'test', '1'], $environment, "{$directory}/projects/Loop");
         $generations = is_file($ledger) ? substr_count((string) file_get_contents($ledger), "\n") : 0;
         // ! The refusal is printed by the generation that would nest deeper —
         //   a grandchild, whose output its own parent captured
         $nested = is_file("{$ledger}.out") ? (string) file_get_contents("{$ledger}.out") : '';

         // @ The chain ends by itself, and says why
         yield assert(
            assertion: $generations > 0 && $generations <= 4,
            description: "a self-re-execing suite is bounded at 4 generations — ran {$generations}"
         );
         yield assert(
            assertion: str_contains($nested, 'refusing to nest deeper'),
            description: 'the run that would nest deeper refuses, and names the cause'
         );
         yield assert(
            assertion: $status === 0 || $status === 1,
            description: "the outer run still terminates with a status (got {$status})"
         );
      }
      finally {
         foreach ([
            "{$directory}/projects/Loop/tests/E2E/autoboot.php",
            "{$directory}/projects/Loop/tests/autoboot.php",
            "{$directory}/projects/Bootgly.projects.php",
            $ledger,
            "{$ledger}.out",
            $entry,
         ] as $file) {
            if (is_file($file) === true) {
               unlink($file);
            }
         }
         foreach ([
            "{$directory}/projects/Loop/tests/E2E",
            "{$directory}/projects/Loop/tests",
            "{$directory}/projects/Loop",
            "{$directory}/projects",
            $directory,
         ] as $path) {
            rmdir($path);
         }
      }
   }
);
