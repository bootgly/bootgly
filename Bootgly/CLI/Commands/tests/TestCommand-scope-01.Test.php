<?php
namespace Bootgly\CLI;


use const BOOTGLY_ROOT_DIR;
use function assert;
use function fclose;
use function file_put_contents;
use function function_exists;
use function getenv;
use function is_resource;
use function ltrim;
use function mkdir;
use function proc_close;
use function proc_open;
use function rmdir;
use function str_contains;
use function str_starts_with;
use function stream_get_contents;
use function unlink;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


return new Test(
   description: 'TestCommand scope contract: inside a project, the working directory selects it',
   test: function () {
      // ? proc_open unavailable — nothing to spawn
      if (function_exists('proc_open') === false) {
         yield assert(assertion: true, description: 'Skipped: proc_open is unavailable');
         return;
      }
      // ? Nested probe guard
      if (getenv('BOOTGLY_TEST_SCOPE_PROBE') === '1') {
         yield assert(assertion: true, description: 'Skipped: nested scope probe');
         return;
      }

      // ! Human environment — agent markers would force the JSON contract
      $human = getenv();
      foreach ([
         'AI_AGENT', 'AMP_CURRENT_THREAD_ID', 'ANTIGRAVITY_AGENT',
         'AUGMENT_AGENT', 'CLAUDECODE', 'CLAUDE_CODE', 'CODEX_SANDBOX',
         'CODEX_THREAD_ID', 'COPILOT_CLI', 'CURSOR_AGENT', 'GEMINI_CLI',
         'OPENCODE', 'OPENCODE_CLIENT', 'REPL_ID',
         'BOOTGLY_AGENT_STDOUT_REDIRECTED', 'BOOTGLY_TTY',
      ] as $variable) {
         unset($human[$variable]);
      }
      $human['BOOTGLY_TEST_SCOPE_PROBE'] = '1';
      $human['BOOTGLY_TEST_VERDICT_PROBE'] = '1';
      $human['BOOTGLY_TEST_VIEW_PROBE'] = '1';
      $human['BOOTGLY_TEST_HELP_PROBE'] = '1';
      $agent = $human;
      $agent['AI_AGENT'] = '1';

      // ! Runner — cwd IS the input under test
      $run = static function (array $arguments, array $environment, string $cwd) : array {
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
            return [-1, '', ''];
         }

         /** @var array<int,resource> $pipes */
         $output = (string) stream_get_contents($pipes[1]);
         $error = (string) stream_get_contents($pipes[2]);
         fclose($pipes[1]);
         fclose($pipes[2]);
         $status = proc_close($Process);

         return [$status, $output, $error];
      };

      // ! Fixture — a kit with `App` (Suites registry), a nested `App/API`
      //   (its own Suites registry), `Zed` (no tests) and `Legacy` (a registry
      //   that returns a Suite — the shape the runner refuses)
      $directory = Temporaries::reserve('testcommand-scope');
      $entry = "{$directory}/bootgly";
      $root = BOOTGLY_ROOT_DIR;
      $files = [
         $entry => "<?php\n"
            . "define('BOOTGLY_WORKING_BASE', __DIR__);\n"
            . "define('BOOTGLY_WORKING_DIR', BOOTGLY_WORKING_BASE . DIRECTORY_SEPARATOR);\n"
            . "(include '{$root}autoboot.php') || exit(1);\n",
         "{$directory}/projects/Bootgly.projects.php" => "<?php\n\n"
            . "return [\n"
            . "   'App' => ['interfaces' => ['CLI']],\n"
            . "   'App/API' => ['interfaces' => ['CLI']],\n"
            . "   'Zed' => ['interfaces' => ['CLI']],\n"
            . "   'Legacy' => ['interfaces' => ['CLI']],\n"
            . "];\n",
         "{$directory}/projects/App/tests/autoboot.php" => "<?php\n\n"
            . "use Bootgly\\ACI\\Tests\\Suites;\n\n"
            . "return new Suites(directories: ['tests/example/']);\n",
         "{$directory}/projects/App/tests/example/autoboot.php" => "<?php\n\n"
            . "use Bootgly\\ACI\\Tests\\Suite;\n\n"
            . "return new Suite(\n"
            . "   autoBoot: __DIR__,\n"
            . "   autoInstance: true,\n"
            . "   autoReport: true,\n"
            . "   autoSummarize: true,\n"
            . "   suiteName: 'AppExample',\n"
            . "   tests: ['1.1-app']\n"
            . ");\n",
         "{$directory}/projects/App/tests/example/1.1-app.Test.php" => "<?php\n\n"
            . "use Bootgly\\ACI\\Tests\\Suite\\Test;\n\n"
            . "return new Test(\n"
            . "   description: 'App case',\n"
            . "   test: function () {\n"
            . "      yield assert(assertion: true, description: 'App ran');\n"
            . "      yield assert(assertion: headers_sent() === false, description: 'the scope line left the output layer untouched');\n"
            . "   }\n"
            . ");\n",
         "{$directory}/projects/App/API/tests/autoboot.php" => "<?php\n\n"
            . "use Bootgly\\ACI\\Tests\\Suites;\n\n"
            . "return new Suites(directories: ['tests/api/']);\n",
         "{$directory}/projects/App/API/tests/api/autoboot.php" => "<?php\n\n"
            . "use Bootgly\\ACI\\Tests\\Suite;\n\n"
            . "return new Suite(\n"
            . "   autoBoot: __DIR__,\n"
            . "   autoInstance: true,\n"
            . "   autoReport: true,\n"
            . "   autoSummarize: true,\n"
            . "   suiteName: 'APISolo',\n"
            . "   tests: ['1.1-api']\n"
            . ");\n",
         // # A registry that returns a Suite: the file would be evaluated
         //   twice (registry, then suite bootstrap), so the runner refuses it
         "{$directory}/projects/Legacy/tests/autoboot.php" => "<?php\n\n"
            . "use Bootgly\\ACI\\Tests\\Suite;\n\n"
            . "return new Suite(\n"
            . "   autoBoot: __DIR__,\n"
            . "   autoInstance: true,\n"
            . "   autoReport: true,\n"
            . "   autoSummarize: true,\n"
            . "   suiteName: 'LegacySolo',\n"
            . "   tests: []\n"
            . ");\n",
         "{$directory}/projects/App/API/tests/api/1.1-api.Test.php" => "<?php\n\n"
            . "use Bootgly\\ACI\\Tests\\Suite\\Test;\n\n"
            . "return new Test(\n"
            . "   description: 'API case',\n"
            . "   test: function () {\n"
            . "      yield assert(assertion: true, description: 'API ran');\n"
            . "   }\n"
            . ");\n",
      ];

      try {
         foreach ([
            "{$directory}/projects/App/tests/example",
            "{$directory}/projects/App/API/tests/api",
            "{$directory}/projects/App/API/tests/deep",
            "{$directory}/projects/Legacy/tests",
            "{$directory}/projects/Zed",
         ] as $path) {
            mkdir($path, 0o700, true);
         }
         foreach ($files as $file => $contents) {
            file_put_contents($file, $contents);
         }

         // @ Inside a registered project: only ITS registry runs, and the
         //   run's first line states the resolution
         [$status, $output] = $run([$entry, 'test'], $human, "{$directory}/projects/App");
         yield assert(
            assertion: $status === 0
               && str_starts_with($output, '[test] scope: projects/App/')
               && str_contains($output, '1.1-app')
               && str_contains($output, '1.1-api') === false,
            description: 'inside a project, only that project runs — and the scope line comes first'
         );

         // @ Deep inside a NESTED project: the longest registered path wins
         [$status, $output] = $run([$entry, 'test'], $human, "{$directory}/projects/App/API/tests/deep");
         yield assert(
            assertion: $status === 0
               && str_starts_with($output, '[test] scope: projects/App/API/')
               && str_contains($output, '1.1-api')
               && str_contains($output, '1.1-app') === false,
            description: 'the longest registered owner wins'
         );

         // @ Suite indices index into the RESOLVED scope
         [$status, $output] = $run([$entry, 'test', '1'], $human, "{$directory}/projects/App");
         yield assert(
            assertion: $status === 0 && str_contains($output, '1.1-app'),
            description: 'a suite index targets the resolved scope'
         );

         // @ A registered project with no test registry refuses, naming it
         [$status, , $error] = $run([$entry, 'test'], $human, "{$directory}/projects/Zed");
         yield assert(
            assertion: $status !== 0
               && str_contains($error, 'projects/Zed carries no tests/'),
            description: 'a registry-less project refuses and names the missing file'
         );

         // @ A registry that returns a Suite is refused, naming the file and
         //   the shape that replaces it. Tolerating it meant evaluating that
         //   file TWICE — as the registry and as the suite bootstrap it stood
         //   for — so a declaration inside it was fatal and every pretest()
         //   ran twice.
         [$status, $output, $error] = $run([$entry, 'test'], $human, "{$directory}/projects/Legacy");
         $said = $output . $error;
         yield assert(
            assertion: $status !== 0
               && str_contains($said, 'must return Suites, not a Suite')
               && str_contains($said, 'projects/Legacy/tests/autoboot.php')
               && str_contains($said, "new Suites(directories: ['tests/example/'])"),
            description: 'a registry that returns a Suite is refused with the shape that replaces it'
         );

         // @ Agents keep the pure-JSON stdout contract — no scope line
         [$status, $output] = $run([$entry, 'test'], $agent, "{$directory}/projects/App");
         yield assert(
            assertion: $status === 0 && str_starts_with(ltrim($output), '{')
               && str_contains($output, '"result"'),
            description: 'an agent run stays pure JSON, scope line included'
         );
      }
      finally {
         foreach ($files as $file => $contents) {
            @unlink($file);
         }
         foreach ([
            "{$directory}/projects/App/API/tests/deep",
            "{$directory}/projects/App/API/tests/api",
            "{$directory}/projects/App/API/tests",
            "{$directory}/projects/App/API",
            "{$directory}/projects/App/tests/example",
            "{$directory}/projects/App/tests",
            "{$directory}/projects/App",
            "{$directory}/projects/Legacy/tests",
            "{$directory}/projects/Legacy",
            "{$directory}/projects/Zed",
            "{$directory}/projects",
            $directory,
         ] as $path) {
            @rmdir($path);
         }
      }
   }
);
