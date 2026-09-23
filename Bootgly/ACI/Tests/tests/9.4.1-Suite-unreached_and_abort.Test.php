<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Results;
use Bootgly\ACI\Tests\Suite;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


return new Test(
   description: 'Suite should account for every registered case — the ones a run never reached and the one a crash interrupted',

   test: new Assertions(Case: function (): Generator {
      // ! Specs on disk, written here so the probe owns its fixtures
      $directory = Temporaries::reserve('suite-unreached');

      $write = static function (string $name, string $body) use ($directory): void {
         file_put_contents(
            "{$directory}/{$name}.Test.php",
            "<?php\n\nuse Bootgly\\ACI\\Tests\\Suite\\Test;\n\n"
               . "return new Test(\n"
               . "   description: '{$name}',\n"
               . "   test: function () {\n"
               . "      {$body}\n"
               . "   }\n"
               . ");\n"
         );
      };
      // @ Run the first N loaded cases, like a runner that stopped early
      $run = static function (Suite $Suite, int $cases): void {
         foreach ($Suite->Tests as $index => $Test) {
            if ($index >= $cases) {
               break;
            }

            $Suite->case = $Test->case ?? 0;
            $Suite->test($Test)?->test();
         }
      };
      $shape = static fn (Suite $Suite): array => array_map(
         static fn (array $record): array => [
            $record['case'], $record['file'], $record['status'], $record['message'],
         ],
         $Suite->records
      );

      // ! Save the runner's global state — the probes fail cases on purpose
      $exitOnFailure = Suite::$exitOnFailure;
      $quiet = Suite::$quiet;
      $enabled = Results::$enabled;
      $Observer = Suite::$Observer;
      $count = Assertions::$count;

      try {
         Suite::$exitOnFailure = false;
         Suite::$quiet = true;
         Results::$enabled = false;
         Suite::$Observer = null;

         $write('u1-passing', 'yield assert(assertion: true, description: "u1");');
         $write('u2-failing', 'yield assert(assertion: false, description: "u2");');
         $write('u3-passing', 'yield assert(assertion: true, description: "u3");');
         $write('u4-passing', 'yield assert(assertion: true, description: "u4");');
         $write('u5-null', 'return null;');

         $tests = ['u1-passing', 'u2-failing', 'u3-passing', 'u4-passing'];

         // @@ A run that stopped after two cases
         //
         //    The cases it never reached used to vanish: the totals silently
         //    shrank to what ran, and a stopped run looked like a complete one.
         $Stopped = new Suite(tests: $tests, autoReport: true, suiteName: 'unreached probe (stopped)');
         $Stopped->autoboot($directory);
         $run($Stopped, 2);
         $Stopped->summarize();

         yield (new Assertion(description: 'every registered case has a record after summarize()'))
            ->expect($shape($Stopped))
            ->to->be([
               [1, 'u1-passing', 'passed', null],
               [2, 'u2-failing', 'failed', 'u2'],
               [3, 'u3-passing', 'skipped', Suite::UNREACHED],
               [4, 'u4-passing', 'skipped', Suite::UNREACHED],
            ])
            ->assert();

         yield (new Assertion(description: 'the counters add up to the registered total'))
            ->expect([$Stopped->passed, $Stopped->failed, $Stopped->skipped])
            ->to->be([1, 1, 2])
            ->assert();

         // @ Idempotent — a second summarize() re-reports nothing
         $Stopped->summarize();
         yield (new Assertion(description: 'a second summarize() settles nothing twice'))
            ->expect([count($Stopped->records), $Stopped->skipped])
            ->to->be([4, 2])
            ->assert();

         // @@ A complete run settles nothing
         $Complete = new Suite(tests: $tests, autoReport: true, suiteName: 'unreached probe (complete)');
         $Complete->autoboot($directory);
         $run($Complete, 4);
         $Complete->summarize();

         yield (new Assertion(description: 'a complete run reports no unreached case'))
            ->expect([count($Complete->records), $Complete->skipped])
            ->to->be([4, 0])
            ->assert();

         // @@ A targeted run whose list a runner narrowed to the target (the
         //    WPI harnesses keep only the targeted spec) and that never ran it
         $Narrowed = new Suite(tests: $tests, autoReport: true, suiteName: 'unreached probe (narrowed)');
         $Narrowed->target = 3;
         $Narrowed->tests = ['u3-passing'];
         $Narrowed->summarize();

         yield (new Assertion(description: 'a narrowed targeted run settles only the target, under its own case'))
            ->expect($shape($Narrowed))
            ->to->be([[3, 'u3-passing', 'skipped', Suite::UNREACHED]])
            ->assert();

         // @@ A case whose only assertion is null — ignored, recorded once
         $Ignored = new Suite(tests: ['u1-passing', 'u5-null'], autoReport: true, suiteName: 'unreached probe (null)');
         $Ignored->autoboot($directory);
         $run($Ignored, 2);
         $Ignored->summarize();

         yield (new Assertion(description: 'an ignored case is a skip with its reason, never "not reached" too'))
            ->expect([$shape($Ignored)[1][2], $shape($Ignored)[1][3], $Ignored->skipped, count($Ignored->records)])
            ->to->be(['skipped', 'ignored: an assertion yielded null', 1, 2])
            ->assert();

         // @@ abort() while a case runs (no record yet) — blamed on that case
         $Running = new Suite(tests: $tests, autoReport: true, suiteName: 'abort probe (running)');
         $Running->autoboot($directory);
         $run($Running, 1);
         $Running->case = 2;
         $Running->abort(new RuntimeException('probe crash'));
         $records = $shape($Running);

         yield (new Assertion(description: 'a crash is blamed on the case that was running'))
            ->expect([$records[1][0], $records[1][1], $records[1][2]])
            ->to->be([2, 'u2-failing', 'failed'])
            ->assert();

         yield (new Assertion(description: 'the crash record names the Throwable and where it was raised'))
            ->expect(str_starts_with((string) $records[1][3], 'RuntimeException: probe crash in ' . __FILE__))
            ->to->be(true)
            ->assert();

         yield (new Assertion(description: 'the cases after the crash are settled as not reached'))
            ->expect([count($records), $records[2][3], $records[3][3], $Running->failed])
            ->to->be([4, Suite::UNREACHED, Suite::UNREACHED, 1])
            ->assert();

         // @@ abort() after the current case already recorded — the next case
         $Between = new Suite(tests: $tests, autoReport: true, suiteName: 'abort probe (between)');
         $Between->autoboot($directory);
         $run($Between, 2);
         $Between->abort(new RuntimeException('between cases'));
         $records = $shape($Between);

         yield (new Assertion(description: 'a crash between cases is blamed on the next case, never on a recorded one'))
            ->expect([$records[1][2], $records[2][0], $records[2][2], count($records)])
            ->to->be(['failed', 3, 'failed', 4])
            ->assert();

         // @@ A spec that fails to load — blamed on itself, not on the one before
         $Loading = new Suite(tests: ['u1-passing', 'u9-missing', 'u3-passing'], autoReport: true, suiteName: 'abort probe (loading)');
         try {
            $Loading->autoboot($directory);
         }
         catch (Throwable $Throwable) {
            $Loading->abort($Throwable);
         }
         $records = $shape($Loading);

         yield (new Assertion(description: 'a spec that cannot load is the case blamed for the crash'))
            ->expect([$records[0][0] ?? null, $records[0][1] ?? null, $records[0][2] ?? null, count($records)])
            ->to->be([2, 'u9-missing', 'failed', 3])
            ->assert();

         // @@ A Suite that does not report its cases (no autoReport): the cases
         //    that ran carry no record, yet they are never "not reached"
         $Silent = new Suite(tests: ['u1-passing', 'u3-passing'], suiteName: 'unreached probe (silent)');
         $Silent->autoboot($directory);
         $run($Silent, 1);
         $Silent->summarize();

         yield (new Assertion(description: 'a case that ran unreported is never settled as not reached'))
            ->expect($shape($Silent))
            ->to->be([[2, 'u3-passing', 'skipped', Suite::UNREACHED]])
            ->assert();

         // @@ abort() after autoboot() loaded every spec, before any case ran
         $Loaded = new Suite(tests: $tests, autoReport: true, suiteName: 'abort probe (loaded)');
         $Loaded->autoboot($directory);
         $Loaded->abort(new RuntimeException('after load'));

         yield (new Assertion(description: 'a crash after loading, before any case, is the suite\'s own (case 0)'))
            ->expect([$shape($Loaded)[0][0], $shape($Loaded)[0][1], $Loaded->skipped])
            ->to->be([0, '', 4])
            ->assert();

         // @@ abort() before any case started — a suite-level failure
         $Boot = new Suite(tests: $tests, autoReport: true, suiteName: 'abort probe (boot)');
         $Boot->abort(new RuntimeException('boot crash'));
         $records = $shape($Boot);

         yield (new Assertion(description: 'a crash before any case is a suite-level failure (case 0)'))
            ->expect([$records[0][0], $records[0][1], $records[0][2], count($records), $Boot->skipped])
            ->to->be([0, '', 'failed', 5, 4])
            ->assert();
      }
      finally {
         Suite::$exitOnFailure = $exitOnFailure;
         Suite::$quiet = $quiet;
         Results::$enabled = $enabled;
         Suite::$Observer = $Observer;
         Assertions::$count = $count;

         array_map('unlink', glob("{$directory}/*.Test.php") ?: []);
         @rmdir($directory);
      }
   })
);
