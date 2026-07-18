<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Results;
use Bootgly\ACI\Tests\Suite;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Suite should record ordered per-assertion results and honor the quiet gate',

   test: new Assertions(Case: function (): Generator {
      // ! Save the runner's global state
      $exitOnFailure = Suite::$exitOnFailure;
      $quiet = Suite::$quiet;
      $enabled = Results::$enabled;

      try {
         // ! Quiet probe — exitOnFailure stays ON: surviving the failing case
         //   below IS the proof that the quiet gate blocks the exit(1).
         //   Results stays off so the probe failure never leaks into agent JSON.
         Suite::$exitOnFailure = true;
         Suite::$quiet = true;
         Results::$enabled = false;

         $Suite = new Suite(tests: [], autoReport: true, suiteName: 'Records probe');

         // # Case 1 — passes with two assertions
         $Passing = new Specification(
            description: 'passing probe',
            test: new Assertions(Case: function (): Generator {
               yield true;
               yield true;
            })
         );
         $Passing->index(case: 1);
         $Suite->test($Passing)?->test();

         // # Case 2 — fails at the third assertion
         $Failing = new Specification(
            description: 'failing probe',
            test: new Assertions(Case: function (): Generator {
               yield true;
               yield true;
               yield false;
            })
         );
         $Failing->index(case: 2);
         $Suite->test($Failing)?->test();

         // # Case 3 — skipped
         $Suite->skip('(probe)');

         // @ Records
         $records = $Suite->records;

         yield (new Assertion(description: 'one record per case, in execution order'))
            ->expect(count($records))
            ->to->be(3)
            ->assert();

         yield (new Assertion(description: 'statuses follow the case outcomes'))
            ->expect([$records[0]['status'], $records[1]['status'], $records[2]['status']])
            ->to->be(['passed', 'failed', 'skipped'])
            ->assert();

         yield (new Assertion(description: 'per-assertion results are captured in order'))
            ->expect([$records[0]['results'], $records[1]['results'], $records[2]['results']])
            ->to->be([[true, true], [true, true, false], []])
            ->assert();

         yield (new Assertion(description: 'a failing record carries a message'))
            ->expect($records[1]['message'] !== null && $records[1]['message'] !== '')
            ->to->be(true)
            ->assert();

         yield (new Assertion(description: 'the Suite counters stay consistent with the records'))
            ->expect([$Suite->passed, $Suite->failed, $Suite->skipped])
            ->to->be([1, 1, 1])
            ->assert();
      }
      finally {
         Suite::$exitOnFailure = $exitOnFailure;
         Suite::$quiet = $quiet;
         Results::$enabled = $enabled;
      }
   })
);
