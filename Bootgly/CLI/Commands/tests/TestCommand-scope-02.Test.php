<?php
namespace Bootgly\CLI;


use const BOOTGLY_ROOT_DIR;
use function assert;
use function fclose;
use function file_put_contents;
use function function_exists;
use function fwrite;
use function getenv;
use function implode;
use function is_resource;
use function mkdir;
use function proc_close;
use function preg_replace;
use function proc_open;
use function rmdir;
use function str_contains;
use function stream_get_contents;
use function substr;
use function unlink;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


return new Test(
   description: 'TestCommand scope contract: projects/ merges, the kit root asks or instructs',
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

      // ! Human environment
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

      // ! Fixture root — reserved before the runner so the entry is capturable
      $directory = Temporaries::reserve('testcommand-scope2');
      $entry = "{$directory}/bootgly";

      // ! Runner — optional stdin feed for the picker probe
      $run = static function (array $environment, string $cwd, null|string $stdin = null) use ($entry) : array {
         $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
         ];
         if ($stdin !== null) {
            $descriptors[0] = ['pipe', 'r'];
         }

         $Process = proc_open(
            [PHP_BINARY, $entry, 'test'],
            $descriptors,
            $pipes,
            $cwd,
            $environment
         );
         if (is_resource($Process) === false) {
            return [-1, '', ''];
         }

         /** @var array<int,resource> $pipes */
         if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
            fclose($pipes[0]);
         }
         $output = (string) stream_get_contents($pipes[1]);
         $error = (string) stream_get_contents($pipes[2]);
         fclose($pipes[1]);
         fclose($pipes[2]);
         $status = proc_close($Process);

         return [$status, $output, $error];
      };

      // ! Fixture — `App` and `App/API` executable, `Zed` registered without
      //   tests, `Rogue` on disk but unregistered
      $root = BOOTGLY_ROOT_DIR;
      $registry = "{$directory}/projects/Bootgly.projects.php";
      $suite = static fn (string $name, string $case): string => "<?php\n\n"
         . "use Bootgly\\ACI\\Tests\\Suite;\n\n"
         . "return new Suite(\n"
         . "   autoBoot: __DIR__,\n"
         . "   autoInstance: true,\n"
         . "   autoReport: true,\n"
         . "   autoSummarize: true,\n"
         . "   suiteName: '{$name}',\n"
         . "   tests: ['{$case}']\n"
         . ");\n";
      $case = static fn (string $marker): string => "<?php\n\n"
         . "use Bootgly\\ACI\\Tests\\Suite\\Test;\n\n"
         . "return new Test(\n"
         . "   description: '{$marker}',\n"
         . "   test: function () {\n"
         . "      yield assert(assertion: true, description: '{$marker}');\n"
         . "   }\n"
         . ");\n";
      $files = [
         $entry => "<?php\n"
            . "define('BOOTGLY_WORKING_BASE', __DIR__);\n"
            . "define('BOOTGLY_WORKING_DIR', BOOTGLY_WORKING_BASE . DIRECTORY_SEPARATOR);\n"
            . "(include '{$root}autoboot.php') || exit(1);\n",
         $registry => "<?php\n\n"
            . "return [\n"
            . "   'App' => ['interfaces' => ['CLI']],\n"
            . "   'App/API' => ['interfaces' => ['CLI']],\n"
            . "   'Zed' => ['interfaces' => ['CLI']],\n"
            . "];\n",
         "{$directory}/projects/App/tests/autoboot.php" => $suite('AppSolo', '1.1-app'),
         "{$directory}/projects/App/tests/1.1-app.Test.php" => $case('App ran'),
         "{$directory}/projects/App/API/tests/autoboot.php" => $suite('APISolo', '1.1-api'),
         "{$directory}/projects/App/API/tests/1.1-api.Test.php" => $case('API ran'),
      ];

      try {
         foreach ([
            "{$directory}/projects/App/tests",
            "{$directory}/projects/App/API/tests",
            "{$directory}/projects/Zed",
            "{$directory}/projects/Rogue",
         ] as $path) {
            mkdir($path, 0o700, true);
         }
         foreach ($files as $file => $contents) {
            file_put_contents($file, $contents);
         }

         // @ From projects/: one merged run — the set first, totals honest
         [$status, $output] = $run($human, "{$directory}/projects");
         yield assert(
            assertion: $status === 0
               && str_contains($output, '[test] projects: 3 registered, 2 executed')
               && str_contains($output, '1.1-app')
               && str_contains($output, '1.1-api')
               && str_contains($output, 'projects/Zed — no tests/'),
            description: 'projects/ merges every executable project and reports registered vs executed'
         );

         // @ An unregistered directory under projects/ refuses loudly
         [$status, , $error] = $run($human, "{$directory}/projects/Rogue");
         yield assert(
            assertion: $status !== 0
               && str_contains($error, 'no registered project owns it'),
            description: 'an unregistered directory under projects/ is refused, not swept over'
         );

         // @ The kit root, headless: state what would have been asked
         [$status, $output, $error] = $run($human, $directory);
         yield assert(
            assertion: $status !== 0
               && str_contains($error, 'cd projects/App && bootgly test')
               && str_contains($error, 'cd projects && bootgly test'),
            description: 'a headless run with no scope instructs on STDERR and exits non-zero'
         );

         // @ The kit root, on a terminal: the picker — Enter takes the aimed
         //   first option (projects/App)
         [$status, $output] = $run(['BOOTGLY_TTY' => '1'] + $human, $directory, "\n");
         $plain = (string) preg_replace('/\x1b\[[0-9;?]*[A-Za-z]/', '', $output);
         $broken = [];
         foreach ([
            'exit 0' => $status === 0,
            'title' => str_contains($plain, 'Which test scope?'),
            'App scope' => str_contains($plain, 'scope: projects/App/ —'),
            'not API' => str_contains($plain, 'scope: projects/App/API/') === false,
            'green card' => str_contains($plain, '0 failed, 0 skipped, 1 passed'),
         ] as $label => $held) {
            if ($held === false) {
               $broken[] = $label;
            }
         }

         yield assert(
            assertion: $broken === [],
            description: 'the kit-root picker runs the chosen project (Enter = the aimed first option)'
               . ($broken === [] ? '' : ' — broken: ' . implode(', ', $broken) . '; tail: ' . substr($plain, -300))
         );

         // @ No registry at all: name the way out
         unlink($registry);
         [$status, , $error] = $run($human, $directory);
         yield assert(
            assertion: $status !== 0
               && str_contains($error, 'no registered projects yet'),
            description: 'a kit with no registered projects says so and exits non-zero'
         );
      }
      finally {
         foreach ($files as $file => $contents) {
            @unlink($file);
         }
         foreach ([
            "{$directory}/projects/App/API/tests",
            "{$directory}/projects/App/API",
            "{$directory}/projects/App/tests",
            "{$directory}/projects/App",
            "{$directory}/projects/Zed",
            "{$directory}/projects/Rogue",
            "{$directory}/projects",
            $directory,
         ] as $path) {
            @rmdir($path);
         }
      }
   }
);
