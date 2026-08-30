<?php
namespace Bootgly\CLI;


use const BOOTGLY_ROOT_DIR;
use const PHP_BINARY;
use function assert;
use function fclose;
use function file_exists;
use function file_get_contents;
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
use function trim;
use function unlink;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


return new Test(
   description: 'TestCommand verdict contract: rendering follows stdout and the agent mode, the sweep and the status follow --fail-fast alone',
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
      if (getenv('BOOTGLY_TEST_VERDICT_PROBE') === '1') {
         yield assert(
            assertion: true,
            description: 'Skipped: nested verdict probe'
         );
         return;
      }

      // ! Environments
      // # Human: agent markers engage the stdout wrapper AND force the list
      //   view, which would mask everything measured here — children must not
      //   inherit them (this suite itself often runs driven by an AI agent).
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
      $human['BOOTGLY_TEST_VERDICT_PROBE'] = '1';
      $human['BOOTGLY_TEST_VIEW_PROBE'] = '1';
      $human['BOOTGLY_TEST_HELP_PROBE'] = '1';
      // # Agent: same base, with a single agent marker restored
      $agent = $human;
      $agent['AI_AGENT'] = '1';

      // ! Runner — stdout and stderr are drained separately, so neither can
      //   stand in for the other (which is the whole point of the verdict line)
      $run = static function (string $entry, array $arguments, array $environment, null|string $cwd = null): array {
         $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
         ];

         $Process = proc_open(
            [PHP_BINARY, $entry, 'test', ...$arguments],
            $descriptors,
            $pipes,
            $cwd ?? BOOTGLY_ROOT_DIR,
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
      // # Targets suite 5 (Bootgly/ABI/Code/__String/Path/): small,
      //   subprocess-free and index-frozen by the root tests/autoboot.php
      $self = static fn (array $arguments, array $environment): array
         => $run(BOOTGLY_ROOT_DIR . 'bootgly', $arguments, $environment);

      // @ A targeted run keeps the list view on a terminal too — the index
      //   means "debug this one", and assertion-level output is the point.
      //   The `Path` check makes a renumbered suite 5 fail loudly instead of
      //   silently measuring some other suite.
      [$status, $output] = $self(['5'], ['BOOTGLY_TTY' => '1'] + $human);
      yield assert(
         assertion: $status === 0 && str_contains($output, ' PASS ')
            && str_contains($output, 'Path')
            && str_contains($output, '╭') === false,
         description: 'A targeted run keeps the list view on a terminal'
      );

      // @ The verdict always reaches STDERR — immune to stdout draining, to
      //   Display muting and to a stdout buffer the process never flushed
      [$status, , $error] = $self(['5'], $human);
      yield assert(
         assertion: $status === 0 && str_contains($error, '[test] PASSED'),
         description: 'A green run states its verdict on STDERR'
      );

      // @ Agents keep the pure-JSON stdout contract — the verdict line would
      //   corrupt the document, so it is theirs to skip
      [$status, $output, $error] = $self(['5'], $agent);
      yield assert(
         assertion: $status === 0 && str_starts_with(ltrim($output), '{')
            && str_contains($output, '"result"')
            && str_contains($error, '[test]') === false,
         description: 'Agent runs emit the JSON document and no verdict line'
      );

      // @ An AI_AGENT that is present but BLANK is not an agent. The wrapper
      //   and Agent::detect() must agree, or the wrapper hijacks stdout for a
      //   child that never writes a document and a green run reports failure.
      [$status, $output, $error] = $self(['5'], ['AI_AGENT' => ''] + $human);
      yield assert(
         assertion: $status === 0 && str_contains($output, ' PASS ')
            && str_contains($error, '[test] PASSED'),
         description: 'A blank AI_AGENT is human, not an agent'
      );

      // @ A child that produced no JSON document keeps stdout parseable, but
      //   its diagnostic must survive on STDERR — emitting a lone newline and
      //   dropping the rest left the caller with nothing to debug
      [$status, $output, $error] = $self(['9999'], $agent);
      yield assert(
         assertion: $status !== 0 && trim($output) === ''
            && str_contains($error, 'no JSON results document'),
         description: 'A child with no JSON document reports why on STDERR'
      );

      // @ --fail-fast is a bare switch — a value is a typo that must not be
      //   read as "on" (nor as "off")
      [$status, $output] = $self(['5', '--fail-fast=0'], $human);
      yield assert(
         assertion: $status === 1 && str_contains($output, 'Invalid --fail-fast'),
         description: '--fail-fast is a bare switch: a value fails with an alert'
      );

      // @ The heatmap is a whole-run dashboard: it cannot paint a run that
      //   stops at its first red case. An EXPLICIT heatmap with --fail-fast is
      //   a contradiction that fails loud instead of being silently discarded.
      [$status, $output] = $self(['5', '--fail-fast', '--view=heatmap'], $human);
      yield assert(
         assertion: $status === 1 && str_contains($output, '--fail-fast')
            && str_contains($output, 'heatmap')
            && str_contains($output, ' PASS ') === false,
         description: 'An explicit --view=heatmap cannot be combined with --fail-fast'
      );

      // ! Full-run shape — a consumer tree with its own BOOTGLY_WORKING_DIR
      //   (exactly how a kit boots) holding two one-case suites. A real full
      //   run is the shape the default view, the sweep and the verdict guard
      //   are about, and 105 framework suites are far too much to spend on it.
      //   `Second/` is the sweep witness: it only runs if `Probe/` did not end
      //   the run.
      $directory = Temporaries::reserve('testcommand-verdict');
      $entry = "{$directory}/bootgly";
      $app = "{$directory}/projects/App";
      $probe = "{$app}/Probe/tests/1.1-probe.Test.php";
      $marker = "{$directory}/cleanup.log";
      $root = BOOTGLY_ROOT_DIR;
      $files = [
         $entry => "<?php\n"
            . "define('BOOTGLY_WORKING_BASE', __DIR__);\n"
            . "define('BOOTGLY_WORKING_DIR', BOOTGLY_WORKING_BASE . DIRECTORY_SEPARATOR);\n"
            . "(include '{$root}autoboot.php') || exit(1);\n",
         // ! A kit fixture is a REGISTERED-project kit: the kit-level test
         //   registry is gone, and the scope comes from the child's cwd
         "{$directory}/projects/Bootgly.projects.php" => "<?php\n\n"
            . "return ['App' => ['interfaces' => ['CLI']]];\n",
         "{$app}/tests/autoboot.php" => "<?php\n\n"
            . "use Bootgly\\ACI\\Tests\\Suites;\n\n"
            . "return new Suites(directories: ['Probe/', 'Second/']);\n",
         "{$app}/Probe/tests/autoboot.php" => "<?php\n\n"
            . "use Bootgly\\ACI\\Tests\\Suite;\n\n"
            . "return new Suite(\n"
            . "   autoBoot: __DIR__,\n"
            . "   autoInstance: true,\n"
            . "   autoReport: true,\n"
            . "   autoSummarize: true,\n"
            // ! Like every real suite autoboot — the sweep legs prove the
            //   runner's contract overrides it, the --fail-fast legs that it
            //   is honoured
            . "   exitOnFailure: true,\n"
            . "   suiteName: 'Probe',\n"
            . "   tests: ['1.1-probe', '1.2-witness']\n"
            . ");\n",
         "{$app}/Second/tests/autoboot.php" => "<?php\n\n"
            . "use Bootgly\\ACI\\Tests\\Suite;\n\n"
            . "return new Suite(\n"
            . "   autoBoot: __DIR__,\n"
            . "   autoInstance: true,\n"
            . "   autoReport: true,\n"
            . "   autoSummarize: true,\n"
            . "   suiteName: 'Second',\n"
            . "   exitOnFailure: true,\n"
            . "   tests: ['1.1-second']\n"
            . ");\n",
         "{$app}/Second/tests/1.1-second.Test.php" => "<?php\n\n"
            . "use Bootgly\\ACI\\Tests\\Suite\\Test;\n\n"
            . "return new Test(\n"
            . "   description: 'The sweep witness',\n"
            . "   test: function () {\n"
            . "      yield assert(assertion: true, description: 'Second ran');\n"
            . "   }\n"
            . ");\n",
         // ! The witness of a TARGETED run: a suite executes every case it
         //   holds unless --fail-fast asked to stop at the first red one
         "{$app}/Probe/tests/1.2-witness.Test.php" => "<?php\n\n"
            . "use Bootgly\\ACI\\Tests\\Suite\\Test;\n\n"
            . "return new Test(\n"
            . "   description: 'The case witness',\n"
            . "   test: function () {\n"
            . "      yield assert(assertion: true, description: 'the witness runs');\n"
            . "   }\n"
            . ");\n",
         // ! Registered only by the self-managed variant below
         "{$app}/Second/tests/1.2-second-witness.Test.php" => "<?php\n\n"
            . "use Bootgly\\ACI\\Tests\\Suite\\Test;\n\n"
            . "return new Test(\n"
            . "   description: 'The second witness',\n"
            . "   test: function () {\n"
            . "      yield assert(assertion: true, description: 'the second witness runs');\n"
            . "   }\n"
            . ");\n",
      ];
      // # The Probe case, in the shapes each contract needs
      $case = static fn (string $body): string => "<?php\n\n"
         . "use Bootgly\\ACI\\Tests\\Suite\\Test;\n\n"
         . "return new Test(\n"
         . "   description: 'Probe',\n"
         . "   test: function () {\n"
         . $body
         . "   }\n"
         . ");\n";
      $green = $case("      yield assert(assertion: true, description: 'the case runs');\n");
      $red = $case("      yield assert(assertion: false, description: 'the case fails');\n");

      try {
         mkdir("{$directory}/projects", 0o700, true);
         mkdir("{$app}/tests", 0o700, true);
         mkdir("{$app}/Probe/tests", 0o700, true);
         mkdir("{$app}/Second/tests", 0o700, true);
         foreach ($files as $file => $contents) {
            file_put_contents($file, $contents);
         }
         file_put_contents($probe, $green);

         // @ A redirected full run is a LOG: the list view, never the live
         //   dashboard. This is the CI default the heatmap used to take.
         [$status, $output] = $run($entry, [], $human, $app);
         yield assert(
            assertion: $status === 0 && str_contains($output, ' PASS ')
               && str_contains($output, '╭') === false,
            description: 'A redirected full run defaults to the list view'
         );

         // @ ...and the same full run on a terminal keeps the dashboard
         [$status, $output] = $run($entry, [], ['BOOTGLY_TTY' => '1'] + $human, $app);
         yield assert(
            assertion: $status === 0 && str_contains($output, '╭')
               && str_contains($output, '■'),
            description: 'A full run on a terminal keeps the heatmap dashboard'
         );

         // @ An explicit --view=heatmap still renders the cards anywhere —
         //   the fallback only ever governs the IMPLICIT default
         [$status, $output] = $run($entry, ['--view=heatmap'], $human, $app);
         yield assert(
            assertion: $status === 0 && str_contains($output, '╭')
               && str_contains($output, '■'),
            description: 'An explicit --view=heatmap still renders a card on a pipe'
         );

         // @ A green agent run accounts for every registered suite — the
         //   control for the fail-fast tally measured below
         [$status, $output] = $run($entry, [], $agent, $app);
         yield assert(
            assertion: $status === 0
               && str_contains($output, '"suites":{"total":2,"failed":0,"skipped":0,"passed":2}'),
            description: 'A green agent run counts every registered suite'
         );

         // @@ Rendering a log instead of a dashboard must NOT change what the
         //    run measures. A redirected full run still visits every suite —
         //    tying exit-on-failure to the rendered view silently turned CI
         //    into a fail-fast run that stopped at the first red suite.
         file_put_contents($probe, $red);

         [$status, $output, $error] = $run($entry, [], $human, $app);
         yield assert(
            assertion: $status === 1 && str_contains($output, 'Ran all test suites')
               && str_contains($output, 'Second'),
            description: 'A redirected full run still sweeps every suite'
         );
         yield assert(
            assertion: str_contains($error, '[test] FAILED'),
            description: 'A swept run with a failing suite reads FAILED'
         );

         // @ ...and an EXPLICIT --view=list is rendering only: the contract
         //   (sweep vs stop) is `--fail-fast`'s alone, so the witness still runs
         [$status, $output, $error] = $run($entry, ['--view=list'], $human, $app);
         yield assert(
            assertion: $status === 1 && str_contains($error, '[test] FAILED')
               && str_contains($output, 'Second'),
            description: 'An explicit --view=list is rendering only: the run still sweeps'
         );

         // @ --fail-fast is the explicit opt-in to stop at the first failing
         //   case, so the witness never runs
         [$status, $output, $error] = $run($entry, ['--fail-fast'], $human, $app);
         yield assert(
            assertion: $status === 1 && str_contains($error, '[test] FAILED')
               && str_contains($output, 'Second') === false,
            description: 'An explicit --fail-fast stops at the first failing case'
         );

         // @ A fail-fast agent run: the document has to say what the run did
         //   NOT do — the suites after the failing one never report an outcome.
         //   Feeding the total incrementally shrank it to the suites that ran,
         //   and `skipped: 0` then read as "everything was covered" over a run
         //   that stopped at the first red suite.
         [$status, $output] = $run($entry, ['--fail-fast'], $agent, $app);
         yield assert(
            assertion: $status === 1
               && str_contains($output, '"suites":{"total":2,"failed":1,"skipped":1,"passed":0}'),
            description: 'A fail-fast agent run reports the suites it never reached as skipped'
         );

         // @@ The agent mode decides the RENDER (the JSON document), never the
         //    contract: an agent full run sweeps like a human one and the
         //    document lists every failure. Tying the contract to the agent
         //    mode left a JSON consumer with no way to ask for a sweep.
         [$status, $output] = $run($entry, [], $agent, $app);
         yield assert(
            assertion: $status === 1 && str_starts_with(ltrim($output), '{')
               && str_contains($output, '"suites":{"total":2,"failed":1,"skipped":0,"passed":1}'),
            description: 'An agent full run sweeps every suite and reports every failure'
         );

         // @ ...and an explicit --view=heatmap under an agent is neither
         //   discarded nor painted: the run sweeps, the document is the output
         [$status, $output] = $run($entry, ['--view=heatmap'], $agent, $app);
         yield assert(
            assertion: $status === 1 && str_starts_with(ltrim($output), '{')
               && str_contains($output, '"suites":{"total":2,"failed":1,"skipped":0,"passed":1}')
               && str_contains($output, '■') === false
               && str_contains($output, '╭') === false,
            description: 'An agent run with an explicit --view=heatmap sweeps and still emits the JSON document'
         );

         // @ A TARGETED run executes every case of the suite it was asked to
         //   run — the index means "this suite", not "stop at its first red"
         [$status, $output, $error] = $run($entry, ['1'], $human, $app);
         yield assert(
            assertion: $status === 1 && str_contains($error, '[test] FAILED')
               && str_contains($output, '1.2-witness'),
            description: 'A targeted run executes every case of the suite'
         );

         // @ ...unless --fail-fast asked to stop at the first failing case
         [$status, $output, $error] = $run($entry, ['1', '--fail-fast'], $human, $app);
         yield assert(
            assertion: $status === 1 && str_contains($error, '[test] FAILED')
               && str_contains($output, '1.2-witness') === false,
            description: 'A targeted run with --fail-fast stops at the first failing case'
         );

         // @ The IMPLICIT heatmap default is downgraded to the list under
         //   --fail-fast: a quiet run never exits from Tester::fail(), so
         //   keeping the dashboard would silently drop the stop
         [$status, $output, $error] = $run($entry, ['--fail-fast'], ['BOOTGLY_TTY' => '1'] + $human, $app);
         yield assert(
            assertion: $status === 1 && str_contains($error, '[test] FAILED')
               && str_contains($output, 'Second') === false
               && str_contains($output, '╭') === false,
            description: 'A --fail-fast run on a terminal renders the list and still stops'
         );

         // @ The agent view arm protects the UNWRAPPED agent path (the stdout
         //   wrapper's own child, a nested `bootgly test`): no card may paint
         //   over the document even when the wrapper is not there to drain it
         [$status, $output] = $run(
            $entry, ['--view=heatmap'], ['BOOTGLY_AGENT_STDOUT_REDIRECTED' => '1'] + $agent, $app
         );
         yield assert(
            assertion: $status === 1 && str_starts_with(ltrim($output), '{')
               && str_contains($output, '■') === false
               && str_contains($output, '╭') === false,
            description: 'An unwrapped agent run never paints the heatmap over the document'
         );

         // @ A cleanup callback armed DURING the sweep still runs on a red
         //   run. Correcting the exit status with a bare exit() cancelled the
         //   rest of the shutdown queue, orphaning forked server workers.
         file_put_contents($probe, $case(
            "      register_shutdown_function(static function (): void {\n"
            . "         file_put_contents('{$marker}', 'cleanup ran', FILE_APPEND);\n"
            . "      });\n\n"
            . "      yield assert(assertion: false, description: 'the case fails');\n"
         ));

         [$status] = $run($entry, ['--fail-fast'], $human, $app);
         yield assert(
            assertion: $status === 1 && file_exists($marker)
               && str_contains((string) file_get_contents($marker), 'cleanup ran'),
            description: 'A cleanup armed during the sweep survives the status correction'
         );

         // @ A suite that fails Suites of its OWN on purpose (every self-test
         //   probe in Bootgly/ACI/Tests does) must not drag the run down with
         //   it — a process-wide failure tally cannot tell the two apart, the
         //   runner's own Suite can. The tally is pinned too, so a tree that
         //   silently ran nothing cannot satisfy this.
         file_put_contents($probe, "<?php\n\n"
            . "use Bootgly\\ACI\\Tests\\Suite;\n"
            . "use Bootgly\\ACI\\Tests\\Suite\\Test;\n\n"
            . "return new Test(\n"
            . "   description: 'Probe that fails a Suite of its own',\n"
            . "   test: function () {\n"
            . "      \$Probe = new Suite(tests: [], exitOnFailure: false, suiteName: 'inner');\n"
            . "      \$Probe->failed++;\n\n"
            . "      yield assert(assertion: true, description: 'the outer case passes');\n"
            . "   }\n"
            . ");\n");

         [$status, $output, $error] = $run($entry, [], $human, $app);
         yield assert(
            assertion: $status === 0
               && str_contains($error, '[test] PASSED — 2 suites: 0 failed, 0 skipped, 2 passed'),
            description: 'A self-test probe failing its own Suite leaves the run green'
         );

         // @@ Under a sweeping run no exit-on-failure fires, so the run carries
         //    on until the case ends the process with a SUCCESS status. Before
         //    the verdict handler this printed nothing at all and exited 0.
         file_put_contents($probe, $case(
            "      yield assert(assertion: true, description: 'the case runs');\n\n"
            . "      exit(0);\n"
         ));

         [$status, $output, $error] = $run($entry, [], $human, $app);
         yield assert(
            assertion: $status === 1 && str_contains($error, '[test] INCOMPLETE'),
            description: 'A sweep ended from inside a case exits 1 and says INCOMPLETE'
         );

         // @@ --fail-fast must survive a suite that manages its own cases.
         //    Suite::$exitOnFailure is a static: an autoboot that passes
         //    `exitOnFailure: false` (every E2E harness does) used to switch
         //    the stop off for every suite AFTER it, and its own failure never
         //    stopped the run. Probe becomes self-managed; Second inherits the
         //    static and gains a witness case.
         file_put_contents("{$app}/Probe/tests/autoboot.php", "<?php\n\n"
            . "use Bootgly\\ACI\\Tests\\Suite;\n\n"
            . "return new Suite(\n"
            . "   autoBoot: __DIR__,\n"
            . "   autoInstance: true,\n"
            . "   autoReport: true,\n"
            . "   autoSummarize: true,\n"
            . "   exitOnFailure: false,\n"
            . "   suiteName: 'Probe',\n"
            . "   tests: ['1.1-probe', '1.2-witness']\n"
            . ");\n");
         file_put_contents("{$app}/Second/tests/autoboot.php", "<?php\n\n"
            . "use Bootgly\\ACI\\Tests\\Suite;\n\n"
            . "return new Suite(\n"
            . "   autoBoot: __DIR__,\n"
            . "   autoInstance: true,\n"
            . "   autoReport: true,\n"
            . "   autoSummarize: true,\n"
            . "   suiteName: 'Second',\n"
            . "   tests: ['1.1-second', '1.2-second-witness']\n"
            . ");\n");
         file_put_contents("{$app}/Second/tests/1.1-second.Test.php", "<?php\n\n"
            . "use Bootgly\\ACI\\Tests\\Suite\\Test;\n\n"
            . "return new Test(\n"
            . "   description: 'The sweep witness',\n"
            . "   test: function () {\n"
            . "      yield assert(assertion: false, description: 'Second fails');\n"
            . "   }\n"
            . ");\n");

         // @ The static does not leak: a green self-managed suite ahead of a
         //   red one leaves the case-level stop armed for the red one
         file_put_contents($probe, $green);
         [$status, $output, $error] = $run($entry, ['--fail-fast'], $human, $app);
         yield assert(
            assertion: $status === 1 && str_contains($error, '[test] FAILED')
               && str_contains($output, '1.1-second')
               && str_contains($output, '1.2-second-witness') === false,
            description: 'A self-managed suite ahead of a red one does not disarm --fail-fast'
         );

         // @ ...and a self-managed suite that FAILS ends the run at its own
         //   end: it runs its remaining cases (its own contract), the suites
         //   after it never start, and they count as skipped
         file_put_contents($probe, $red);
         [$status, $output, $error] = $run($entry, ['--fail-fast'], $human, $app);
         yield assert(
            assertion: $status === 1
               && str_contains($error, '[test] FAILED — 2 suites: 1 failed, 1 skipped, 0 passed')
               && str_contains($output, '1.2-witness')
               && str_contains($output, 'Second') === false,
            description: 'A failed self-managed suite ends a --fail-fast run at its own end'
         );
         [$status, $output] = $run($entry, ['--fail-fast'], $agent, $app);
         yield assert(
            assertion: $status === 1
               && str_contains($output, '"suites":{"total":2,"failed":1,"skipped":1,"passed":0}'),
            description: 'The document of that run counts the suites it never reached as skipped'
         );
      }
      finally {
         @unlink($probe);
         @unlink($marker);
         foreach ($files as $file => $contents) {
            @unlink($file);
         }
         @rmdir("{$app}/Second/tests");
         @rmdir("{$app}/Second");
         @rmdir("{$app}/Probe/tests");
         @rmdir("{$app}/Probe");
         @rmdir("{$app}/tests");
         @rmdir($app);
         @rmdir("{$directory}/projects");
         @rmdir($directory);
      }
   }
);
