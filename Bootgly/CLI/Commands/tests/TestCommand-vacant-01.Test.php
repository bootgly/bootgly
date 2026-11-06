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
use const PHP_BINARY;
use function array_column;
use function array_merge;
use function array_values;
use function assert;
use function dirname;
use function fclose;
use function file_put_contents;
use function function_exists;
use function getenv;
use function implode;
use function in_array;
use function is_array;
use function is_dir;
use function is_resource;
use function json_decode;
use function json_encode;
use function mkdir;
use function proc_close;
use function proc_open;
use function str_contains;
use function stream_get_contents;
use function strrpos;
use function substr;
use function trim;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;
use Bootgly\API\Environment\Agent;


/**
 * A closure Suite whose autoBoot returns without running or recording any of
 * its cases fails the run — it is never reported as a green suite with nothing
 * in it (TEST-14: an E2E harness whose `test` command chain broke off).
 */
return new Test(
   description: 'TestCommand: a closure suite that ran none of its cases fails the run, a suite that skipped its case does not',
   test: function () {
      // ? proc_open unavailable — nothing to spawn
      if (function_exists('proc_open') === false) {
         yield assert(assertion: true, description: 'Skipped: proc_open is unavailable');
         return;
      }
      // ? Nested probe guard — the child must never re-run this very suite
      if (getenv('BOOTGLY_TEST_VACANT_PROBE') === '1') {
         yield assert(assertion: true, description: 'Skipped: nested vacant probe');
         return;
      }

      // ! Agent environment — the child prints the JSON report
      $environment = getenv();
      foreach ([...array_merge(...array_values(Agent::MARKERS)), 'AI_AGENT', 'BOOTGLY_AGENT_STDOUT_REDIRECTED', 'BOOTGLY_TTY'] as $variable) {
         unset($environment[$variable]);
      }
      $environment['BOOTGLY_TEST_VACANT_PROBE'] = '1';
      $environment['AI_AGENT'] = '1';

      // ! A consumer tree (exactly how a kit boots) with three closure suites:
      //   one that returns having done nothing and one that skips its only
      //   case — plus a directory suite whose case passes
      $directory = Temporaries::reserve('testcommand-vacant');
      $entry = "{$directory}/bootgly";
      $app = "{$directory}/projects/App";
      $root = BOOTGLY_ROOT_DIR;
      $suite = static fn (string $name, string $body, string $tests): string => "<?php\n\n"
         . "use Bootgly\\ACI\\Tests\\Suite;\n\n"
         . "return new Suite(\n"
         . "   autoBoot: function (Suite \$Suite): true {\n"
         . "      {$body}\n"
         . "      return true;\n"
         . "   },\n"
         . "   suiteName: '{$name}',\n"
         . "   tests: {$tests}\n"
         . ");\n";
      $files = [
         $entry => "<?php\n"
            . "define('BOOTGLY_WORKING_BASE', __DIR__);\n"
            . "define('BOOTGLY_WORKING_DIR', BOOTGLY_WORKING_BASE . DIRECTORY_SEPARATOR);\n"
            . "(include '{$root}autoboot.php') || exit(1);\n",
         "{$directory}/projects/Bootgly.projects.php" => "<?php\n\n"
            . "return ['App' => ['interfaces' => ['CLI']]];\n",
         "{$app}/tests/autoboot.php" => "<?php\n\n"
            . "use Bootgly\\ACI\\Tests\\Suites;\n\n"
            . "return new Suites(directories: ['Vacant/', 'Settled/', 'Empty/', 'Skipped/', 'Ran/']);\n",
         // # Returns without running, skipping or recording anything — the
         //   shape a harness leaves when its command chain breaks off
         "{$app}/Vacant/tests/autoboot.php" => $suite('Vacant', '// nothing ran', "['1.1-first', '1.2-second']"),
         // # Ran nothing, then summarized — every case settled as "not reached"
         "{$app}/Settled/tests/autoboot.php" => $suite('Settled', '$Suite->summarize();', "['4.1-first', '4.2-second']"),
         // # Lists no case at all — nothing was expected, so nothing is missing
         "{$app}/Empty/tests/autoboot.php" => $suite('Empty', '// nothing to run', '[]'),
         // # Skips its one case on purpose — a recorded outcome, not a vacant run
         "{$app}/Skipped/tests/autoboot.php" => $suite('Skipped', "\$Suite->skip('not here', '2.1-only');", "['2.1-only']"),
         // # A directory suite whose one case passes — the control
         "{$app}/Ran/tests/autoboot.php" => "<?php\n\n"
            . "use Bootgly\\ACI\\Tests\\Suite;\n\n"
            . "return new Suite(\n"
            . "   autoBoot: __DIR__,\n"
            . "   autoInstance: true,\n"
            . "   autoReport: true,\n"
            . "   autoSummarize: true,\n"
            . "   suiteName: 'Ran',\n"
            . "   tests: ['3.1-pass']\n"
            . ");\n",
         "{$app}/Ran/tests/3.1-pass.Test.php" => "<?php\n\n"
            . "use Bootgly\\ACI\\Tests\\Suite\\Test;\n\n"
            . "return new Test(\n"
            . "   description: 'passes',\n"
            . "   test: fn (): bool => true\n"
            . ");\n",
      ];
      foreach ($files as $file => $contents) {
         if (is_dir(dirname($file)) === false) {
            mkdir(dirname($file), 0o755, true);
         }
         file_put_contents($file, $contents);
      }

      // @ The run, from inside the project
      $Process = proc_open(
         [PHP_BINARY, $entry, 'test'],
         [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
         $pipes,
         $app,
         $environment
      );
      $output = '';
      $error = '';
      $status = -1;
      if (is_resource($Process)) {
         /** @var array<int,resource> $pipes */
         $output = (string) stream_get_contents($pipes[1]);
         $error = (string) stream_get_contents($pipes[2]);
         fclose($pipes[1]);
         fclose($pipes[2]);
         $status = proc_close($Process);
      }
      $start = strrpos($output, "\n{");
      $document = json_decode(trim($start === false ? $output : substr($output, $start)), true);
      $failures = is_array($document) ? ($document['failures'] ?? []) : [];
      $suites = array_column($failures, 'suite');
      $vacant = false;
      $settled = false;
      foreach ($failures as $failure) {
         $guarded = str_contains((string) ($failure['message'] ?? ''), 'returned without running or recording any of its cases');
         if ($guarded && ($failure['suite'] ?? '') === 'Vacant') {
            $vacant = true;
         }
         if ($guarded && ($failure['suite'] ?? '') === 'Settled') {
            $settled = true;
         }
      }
      $evidence = 'status=' . $status . ', result=' . (is_array($document) ? ($document['result'] ?? '?') : 'no document')
         . ', failed suites=' . implode(',', $suites) . ', failures=' . json_encode($failures)
         . ($document === null ? ", stdout tail: {$output}, stderr: {$error}" : '');

      yield assert(
         assertion: $status !== 0
            && is_array($document)
            && ($document['result'] ?? null) === 'failed'
            && $vacant,
         description: "the vacant suite fails the run, and says its autoBoot ran none of its cases ($evidence)"
      );

      yield assert(
         assertion: $settled,
         description: "a suite that ran nothing and only summarized — every case \"not reached\" — fails the run too ($evidence)"
      );

      yield assert(
         assertion: is_array($document)
            && in_array('Skipped', $suites, true) === false
            && in_array('Empty', $suites, true) === false
            && in_array('Ran', $suites, true) === false
            && ($document['cases']['passed'] ?? 0) === 1,
         description: "a suite that skipped its case, one that lists no case and one whose case passed are not failed ($evidence)"
      );
   }
);
