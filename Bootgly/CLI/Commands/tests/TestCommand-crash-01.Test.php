<?php
namespace Bootgly\CLI;


use const BOOTGLY_ROOT_DIR;
use const GLOB_BRACE;
use const PHP_BINARY;
use function array_merge;
use function array_values;
use function assert;
use function fclose;
use function file_put_contents;
use function function_exists;
use function getenv;
use function glob;
use function is_array;
use function is_dir;
use function is_resource;
use function json_decode;
use function mkdir;
use function proc_close;
use function proc_open;
use function rmdir;
use function rsort;
use function str_contains;
use function stream_get_contents;
use function strrpos;
use function substr;
use function trim;
use function unlink;

use Bootgly\ACI\Tests\Suite;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;
use Bootgly\API\Environment\Agent;


return new Test(
   description: 'TestCommand crash contract: a Throwable escaping a suite is a failed case that names its cause, and the cases it never reached still count',
   test: function () {
      // ? proc_open unavailable — nothing to spawn
      if (function_exists('proc_open') === false) {
         yield assert(
            assertion: true,
            description: 'Skipped: proc_open is unavailable'
         );
         return;
      }
      // ? Nested probe guard — children must never re-run this very suite
      if (getenv('BOOTGLY_TEST_CRASH_PROBE') === '1') {
         yield assert(
            assertion: true,
            description: 'Skipped: nested crash probe'
         );
         return;
      }

      // ! Agent environment — one marker, so the child prints the JSON report
      $environment = getenv();
      foreach ([...array_merge(...array_values(Agent::MARKERS)), 'AI_AGENT', 'BOOTGLY_AGENT_STDOUT_REDIRECTED', 'BOOTGLY_TTY'] as $variable) {
         unset($environment[$variable]);
      }
      $environment['BOOTGLY_TEST_CRASH_PROBE'] = '1';
      $environment['AI_AGENT'] = '1';

      // ! A consumer tree (exactly how a kit boots) holding three suites:
      //   one that crashes mid-run, one whose bootstrap cannot even load, and
      //   a green witness proving the sweep went on past both
      $directory = Temporaries::reserve('testcommand-crash');
      $entry = "{$directory}/bootgly";
      $app = "{$directory}/projects/App";
      $root = BOOTGLY_ROOT_DIR;
      $case = static fn (string $description): string => "<?php\n\n"
         . "use Bootgly\\ACI\\Tests\\Suite\\Test;\n\n"
         . "return new Test(\n"
         . "   description: '{$description}',\n"
         . "   test: function () {\n"
         . "      yield assert(assertion: true, description: '{$description}');\n"
         . "   }\n"
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
            . "return new Suites(directories: ['Crash/', 'Broken/', 'Witness/']);\n",
         // # A self-managed suite (like the live E2E boots): it runs case 1,
         //   then a Throwable escapes while case 2 runs
         "{$app}/Crash/tests/autoboot.php" => "<?php\n\n"
            . "use Bootgly\\ACI\\Tests\\Suite;\n\n"
            . "return new Suite(\n"
            . "   autoBoot: function (Suite \$Suite): true {\n"
            . "      \$Suite->autoboot(__DIR__);\n"
            . "      \$Suite->case = 1;\n"
            . "      \$Suite->test(\$Suite->Tests[0])?->test();\n"
            . "      \$Suite->case = 2;\n"
            . "      throw new \\RuntimeException('crash probe: case 2 escaped');\n"
            . "   },\n"
            . "   autoReport: true,\n"
            . "   suiteName: 'Crash',\n"
            . "   tests: ['1.1-first', '1.2-second', '1.3-third']\n"
            . ");\n",
         "{$app}/Crash/tests/1.1-first.Test.php" => $case('first'),
         "{$app}/Crash/tests/1.2-second.Test.php" => $case('second'),
         "{$app}/Crash/tests/1.3-third.Test.php" => $case('third'),
         // # A bootstrap that throws before it returns a Suite
         "{$app}/Broken/tests/autoboot.php" => "<?php\n\n"
            . "throw new \\RuntimeException('crash probe: the bootstrap cannot load');\n",
         // # The sweep witness
         "{$app}/Witness/tests/autoboot.php" => "<?php\n\n"
            . "use Bootgly\\ACI\\Tests\\Suite;\n\n"
            . "return new Suite(\n"
            . "   autoBoot: __DIR__,\n"
            . "   autoInstance: true,\n"
            . "   autoReport: true,\n"
            . "   autoSummarize: true,\n"
            . "   suiteName: 'Witness',\n"
            . "   tests: ['1.1-witness']\n"
            . ");\n",
         "{$app}/Witness/tests/1.1-witness.Test.php" => $case('witness'),
      ];

      try {
         foreach (["{$app}/tests", "{$app}/Crash/tests", "{$app}/Broken/tests", "{$app}/Witness/tests"] as $path) {
            mkdir($path, 0o700, true);
         }
         foreach ($files as $file => $contents) {
            file_put_contents($file, $contents);
         }

         // @ Run the consumer tree as an agent
         $Process = proc_open(
            [PHP_BINARY, $entry, 'test'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $app,
            $environment
         );
         $output = '';
         $status = -1;
         if (is_resource($Process)) {
            /** @var array<int,resource> $pipes */
            $output = (string) stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_close($Process);
         }

         // ! The report is the last JSON document on stdout
         $position = strrpos($output, "\n{\"result\"");
         $document = json_decode(trim($position === false ? $output : substr($output, $position)), true);
         $report = is_array($document) ? $document : [];
         $failures = is_array($report['failures'] ?? null) ? $report['failures'] : [];
         /** @var array<string,mixed> $crash */
         $crash = [];
         /** @var array<string,mixed> $broken */
         $broken = [];
         foreach ($failures as $failure) {
            if (is_array($failure) && str_contains((string) ($failure['message'] ?? ''), 'case 2 escaped')) {
               $crash = $failure;
            }
            if (is_array($failure) && str_contains((string) ($failure['message'] ?? ''), 'bootstrap cannot load')) {
               $broken = $failure;
            }
         }

         yield assert(
            assertion: $status === 1 && ($report['result'] ?? null) === 'failed',
            description: 'A crashing run fails (exit 1, result failed)'
         );

         yield assert(
            assertion: ($crash['suite'] ?? null) === 'Crash'
               && ($crash['case'] ?? null) === 2
               && ($crash['file'] ?? null) === '1.2-second'
               && str_contains((string) ($crash['message'] ?? ''), 'RuntimeException: crash probe: case 2 escaped in '),
            description: 'The escaped Throwable is a failed case: the case that was running, with class, message and origin'
         );

         yield assert(
            assertion: ($broken['case'] ?? null) === 0
               && str_contains((string) ($broken['suite'] ?? ''), 'Broken')
               && str_contains((string) ($broken['message'] ?? ''), 'RuntimeException: crash probe: the bootstrap cannot load'),
            description: 'A bootstrap that cannot load is a suite-level failure (case 0) naming its cause'
         );

         // ! Crash: 1 passed + 1 failed + 1 not reached; Broken: 1 failed
         //   entry; Witness: 1 passed — every registered case accounted for
         yield assert(
            assertion: ($report['cases'] ?? null) === ['total' => 5, 'failed' => 2, 'skipped' => 1, 'passed' => 2],
            description: 'The case the crash kept from running is counted as skipped, and the witness still ran'
         );

         yield assert(
            assertion: ($report['suites'] ?? null) === ['total' => 3, 'failed' => 2, 'skipped' => 0, 'passed' => 1],
            description: 'The sweep went on past both failed suites'
         );

         yield assert(
            assertion: Suite::UNREACHED === 'not reached',
            description: 'Unreached cases carry the documented message'
         );

         // @ A targeted case the suite does not register is refused as a
         //   suite-level failure — never a list of cases "not reached"
         $Process = proc_open(
            [PHP_BINARY, $entry, 'test', '3', '9'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $app,
            $environment
         );
         $output = '';
         if (is_resource($Process)) {
            /** @var array<int,resource> $pipes */
            $output = (string) stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($Process);
         }
         $position = strrpos($output, "\n{\"result\"");
         $document = json_decode(trim($position === false ? $output : substr($output, $position)), true);
         $targeted = is_array($document) ? $document : [];
         $refusal = is_array($targeted['failures'][0] ?? null) ? $targeted['failures'][0] : [];

         yield assert(
            assertion: ($targeted['cases'] ?? null) === ['total' => 1, 'failed' => 1, 'skipped' => 0, 'passed' => 0]
               && ($refusal['case'] ?? null) === 0
               && str_contains((string) ($refusal['message'] ?? ''), 'Test case index 9 does not exist'),
            description: 'An unknown targeted case is one suite-level failure naming the index'
         );
      }
      finally {
         // @ Tear the tree down
         $paths = glob("{$directory}/{,*/,*/*/,*/*/*/,*/*/*/*/}*", GLOB_BRACE) ?: [];
         rsort($paths);
         foreach ($paths as $path) {
            is_dir($path) ? @rmdir($path) : @unlink($path);
         }
         @rmdir($directory);
      }
   }
);
