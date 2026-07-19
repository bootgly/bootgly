<?php
namespace Bootgly\CLI;


use const BOOTGLY_ROOT_DIR;
use const PHP_BINARY;
use function assert;
use function fclose;
use function function_exists;
use function getenv;
use function is_resource;
use function ltrim;
use function proc_close;
use function proc_open;
use function str_contains;
use function str_starts_with;
use function stream_get_contents;

use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'TestCommand --view contract: list unchanged, heatmap cards, agent JSON',
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
      if (getenv('BOOTGLY_TEST_VIEW_PROBE') === '1') {
         yield assert(
            assertion: true,
            description: 'Skipped: nested view probe'
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
         'BOOTGLY_AGENT_STDOUT_REDIRECTED', 'BOOTGLY_TTY',
      ] as $variable) {
         unset($human[$variable]);
      }
      $human['BOOTGLY_TEST_VIEW_PROBE'] = '1';
      $human['BOOTGLY_TEST_HELP_PROBE'] = '1';
      // # Agent: same base, with a single agent marker restored
      $agent = $human;
      $agent['AI_AGENT'] = '1';

      // ! Runner — targets suite 4 (Bootgly/ABI/Data/__String/Path/): small,
      //   subprocess-free and index-frozen by the root tests/autoboot.php
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

      // @ --view=heatmap (human): card rendered, no per-case rows, footer kept
      [$status, $output] = $run(['4', '--view=heatmap'], $human);
      yield assert(
         assertion: $status === 0,
         description: '--view=heatmap exits with success on a green suite'
      );
      yield assert(
         assertion: str_contains($output, '╭') && str_contains($output, '■')
            && str_contains($output, '%') && str_contains($output, ' / ')
            && str_contains($output, 'Path'),
         description: '--view=heatmap renders the suite dashboard card'
      );
      yield assert(
         assertion: str_contains($output, ' PASS ') === false,
         description: '--view=heatmap mutes the per-case list output'
      );
      yield assert(
         assertion: str_contains($output, 'Ran all test suites'),
         description: '--view=heatmap keeps the global suites footer'
      );

      // @ --view=list (human): current output, no cards
      [$status, $output] = $run(['4', '--view=list'], $human);
      yield assert(
         assertion: $status === 0 && str_contains($output, ' PASS ')
            && str_contains($output, '╭') === false,
         description: '--view=list keeps the current per-case output'
      );

      // @ Default (human, targeted run): the list view for focused debugging
      [$status, $output] = $run(['4'], $human);
      yield assert(
         assertion: $status === 0 && str_contains($output, ' PASS ')
            && str_contains($output, '╭') === false,
         description: 'A targeted run defaults to the list view'
      );

      // @ --view=heatmap (human, forced TTY): the card streams live
      [$status, $output] = $run(['4', '--view=heatmap'], ['BOOTGLY_TTY' => '1'] + $human);
      yield assert(
         assertion: $status === 0 && str_contains($output, "\e[?25l")
            && str_contains($output, "\e[?25h"),
         description: 'A TTY heatmap run streams the card live (cursor hidden while painting)'
      );

      // @ Invalid view (human): alert + failure exit
      [$status, $output] = $run(['--view=bogus'], $human);
      yield assert(
         assertion: $status === 1 && str_contains($output, 'Invalid --view'),
         description: 'An invalid --view value fails with an alert'
      );

      // @ --view=heatmap (agent): the wrapper owns stdout — pure JSON, no cards
      [$status, $output] = $run(['4', '--view=heatmap'], $agent);
      yield assert(
         assertion: $status === 0 && str_starts_with(ltrim($output), '{')
            && str_contains($output, '"result"')
            && str_contains($output, '■') === false,
         description: 'Agent runs ignore the view and emit the JSON document'
      );

      // @ Invalid suite index (heatmap): still a failure exit
      [$status, $output] = $run(['9999', '--view=heatmap'], $agent);
      yield assert(
         assertion: $status === 1,
         description: 'An unknown suite index still exits with failure under heatmap'
      );
   }
);
