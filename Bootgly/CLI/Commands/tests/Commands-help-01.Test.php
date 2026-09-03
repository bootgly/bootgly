<?php
namespace Bootgly\CLI;


use const BOOTGLY_ROOT_DIR;
use const PHP_BINARY;
use function assert;
use function fclose;
use function function_exists;
use function getenv;
use function is_resource;
use function proc_close;
use function proc_open;
use function str_contains;
use function stream_get_contents;

use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Global --help/-h is standardized across every core command',
   test: function () {
      // ? proc_open unavailable — nothing to spawn
      if (function_exists('proc_open') === false) {
         yield assert(
            assertion: true,
            description: 'Skipped: proc_open is unavailable'
         );
         return;
      }
      // ? Nested probe guard — defensive against any re-entrant spawn
      if (getenv('BOOTGLY_TEST_HELP_PROBE') === '1') {
         yield assert(
            assertion: true,
            description: 'Skipped: nested help probe'
         );
         return;
      }

      // ! Human environment — scrub agent markers so children emit human output
      $human = getenv();
      foreach ([
         'AI_AGENT', 'AMP_CURRENT_THREAD_ID', 'ANTIGRAVITY_AGENT',
         'AUGMENT_AGENT', 'CLAUDECODE', 'CLAUDE_CODE', 'CODEX_SANDBOX',
         'CODEX_THREAD_ID', 'COPILOT_CLI', 'CURSOR_AGENT', 'GEMINI_CLI',
         'OPENCODE', 'OPENCODE_CLIENT', 'REPL_ID',
         'BOOTGLY_AGENT_STDOUT_REDIRECTED',
      ] as $variable) {
         unset($human[$variable]);
      }
      $human['BOOTGLY_TEST_HELP_PROBE'] = '1';

      // ! Runner
      $run = static function (array $arguments) use ($human): array {
         $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
         ];

         $process = proc_open(
            [PHP_BINARY, BOOTGLY_ROOT_DIR . 'bootgly', ...$arguments],
            $descriptors,
            $pipes,
            BOOTGLY_ROOT_DIR,
            $human
         );
         if (is_resource($process) === false) {
            return [-1, ''];
         }

         /** @var array<int,resource> $pipes */
         $output = (string) stream_get_contents($pipes[1]);
         fclose($pipes[1]);
         fclose($pipes[2]);
         $status = proc_close($process);

         return [$status, $output];
      };

      // @ setup --help — renders help instead of running the installer
      [$status, $output] = $run(['setup', '--help']);
      yield assert(
         assertion: $status === 0,
         description: 'setup --help exits with success (never runs the installer)'
      );
      yield assert(
         assertion: str_contains($output, 'Commands options'),
         description: 'setup --help renders the Commands options box'
      );
      yield assert(
         assertion: str_contains($output, '--help, -h'),
         description: 'setup --help lists --help, -h as a global option'
      );
      yield assert(
         assertion: str_contains($output, 'Uninstall Bootgly CLI'),
         description: 'setup --help lists the command-local options'
      );

      // @ lint -h — the short form is now standardized (it lacked -h before)
      [$status, $output] = $run(['lint', '-h']);
      yield assert(
         assertion: $status === 0 && str_contains($output, '--help, -h'),
         description: 'lint -h renders help via the standardized short form'
      );

      // @ kit --help — a command-local flag surfaces alongside the globals
      [$status, $output] = $run(['kit', '--help']);
      yield assert(
         assertion: $status === 0 && str_contains($output, '--resources'),
         description: 'kit --help lists its --resources local option'
      );

      // @ demo --help — a command with no local options still inherits help
      [$status, $output] = $run(['demo', '--help']);
      yield assert(
         assertion: $status === 0 && str_contains($output, '--help, -h'),
         description: 'demo --help renders the inherited global options'
      );

      // @ test benchmark --help — subcommand-specific help is preserved
      [, $output] = $run(['test', 'benchmark', '--help']);
      yield assert(
         assertion: str_contains($output, 'benchmark'),
         description: 'test benchmark --help still reaches the benchmark help'
      );
   }
);
