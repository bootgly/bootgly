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
use function trim;

use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'TestCommand --help/-h contract: usage rendered, no suites run',
   test: function () {
      // ? proc_open unavailable — nothing to spawn
      if (function_exists('proc_open') === false) {
         yield assert(
            assertion: true,
            description: 'Skipped: proc_open is unavailable'
         );
         return;
      }
      // ? Nested probe guard — a broken --help would re-run this very suite
      //   from the child and fork endlessly
      if (getenv('BOOTGLY_TEST_HELP_PROBE') === '1') {
         yield assert(
            assertion: true,
            description: 'Skipped: nested help probe'
         );
         return;
      }

      // ! Environments
      // # Human: agent env vars engage the stdout wrapper — children must not
      //   inherit them (this suite itself often runs driven by an AI agent)
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
      // # Agent: same base, with a single agent marker restored
      $agent = $human;
      $agent['AI_AGENT'] = '1';

      // ! Runner
      $run = static function (array $arguments, array $environment): array {
         $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
         ];

         $process = proc_open(
            [PHP_BINARY, BOOTGLY_ROOT_DIR . 'bootgly', 'test', ...$arguments],
            $descriptors,
            $pipes,
            BOOTGLY_ROOT_DIR,
            $environment
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

      // @ --help (human): usage rendered, success, no suite ran
      [$status, $output] = $run(['--help'], $human);
      yield assert(
         assertion: $status === 0,
         description: '--help exits with success'
      );
      yield assert(
         assertion: str_contains($output, 'Test usage'),
         description: '--help renders the usage section'
      );
      yield assert(
         assertion: str_contains($output, '--coverage-report'),
         description: '--help lists the coverage options'
      );
      yield assert(
         assertion: str_contains($output, 'Ran all test suites') === false,
         description: '--help does not run the test suites'
      );

      // @ -h (human): same contract as --help
      [$status, $output] = $run(['-h'], $human);
      yield assert(
         assertion: $status === 0 && str_contains($output, 'Test usage'),
         description: '-h exits with success and renders the usage section'
      );

      // @ --help (agent): bypasses the agent stdout wrapper — raw help text
      [$status, $output] = $run(['--help'], $agent);
      yield assert(
         assertion: $status === 0 && str_contains($output, 'Test usage'),
         description: '--help bypasses the agent stdout redirection'
      );

      // @ Invalid suite (agent): the wrapper engages — the child failure alert
      //   is swallowed and only the exit status crosses the boundary
      [$status, $output] = $run(['9999'], $agent);
      yield assert(
         assertion: $status === 1 && trim($output) === '',
         description: 'An agent-driven run keeps stdout owned by the wrapper'
      );
   }
);
