<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Results;
use Bootgly\ACI\Tests\Suite;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


return new Test(
   description: 'Suite should attribute every record to the case that actually ran',

   test: new Assertions(Case: function (): Generator {
      // ! Three specs on disk, written here so the probe owns its fixtures
      $directory = Temporaries::reserve('suite-attribution');

      $write = static function (string $name, bool $passes) use ($directory): void {
         file_put_contents(
            "{$directory}/{$name}.Test.php",
            "<?php\n\nuse Bootgly\\ACI\\Tests\\Suite\\Test;\n\n"
               . "return new Test(\n"
               . "   description: '{$name}',\n"
               . "   test: function () {\n"
               . "      yield assert(assertion: " . ($passes ? 'true' : 'false') . ","
               . " description: '{$name}');\n"
               . "   }\n"
               . ");\n"
         );
      };

      // ! Save the runner's global state — the probe runs failing cases
      $exitOnFailure = Suite::$exitOnFailure;
      $quiet = Suite::$quiet;
      $enabled = Results::$enabled;

      try {
         Suite::$exitOnFailure = false;
         Suite::$quiet = true;
         Results::$enabled = false;

         $write('b1-passing', true);
         $write('b2-passing', true);
         $write('b3-failing', false);

         // @@ A targeted run — `bootgly test <suite> 3`
         //
         //    autoboot() walks past cases 1 and 2 without touching the list's
         //    internal array pointer, so a cursor taken from that pointer named
         //    the FIRST spec — a passing one — for the failing case 3.
         $Targeted = new Suite(
            tests: ['b1-passing', 'b2-passing', 'b3-failing'],
            autoReport: true,
            exitOnFailure: false,
            suiteName: 'attribution probe (targeted)'
         );
         $Targeted->target = 3;
         $Targeted->autoboot($directory);
         $Targeted->autoinstance(true);

         yield (new Assertion(description: 'a targeted run records exactly the case it ran'))
            ->expect(count($Targeted->records))
            ->to->be(1)
            ->assert();

         yield (new Assertion(description: 'the failure is attributed to the file that failed'))
            ->expect([
               $Targeted->records[0]['case'],
               $Targeted->records[0]['file'],
               $Targeted->records[0]['status'],
            ])
            ->to->be([3, 'b3-failing', 'failed'])
            ->assert();

         // @@ A listed `_private` case whose file is absent
         //
         //    It used to be dropped: the entry vanished from every counter AND
         //    handed its name to the next case that ran, which then reported a
         //    PASS under a file that does not exist on disk.
         $Private = new Suite(
            tests: ['b1-passing', '_absent-private', 'b2-passing'],
            autoReport: true,
            exitOnFailure: false,
            suiteName: 'attribution probe (private)'
         );
         $Private->autoboot($directory);
         $Private->autoinstance(true);

         yield (new Assertion(description: 'every listed case produces a record'))
            ->expect(count($Private->records))
            ->to->be(count($Private->tests))
            ->assert();

         yield (new Assertion(description: 'each record carries its own case index and file'))
            ->expect(array_map(
               static fn (array $record): array => [
                  $record['case'], $record['file'], $record['status']
               ],
               $Private->records
            ))
            ->to->be([
               [1, 'b1-passing', 'passed'],
               [2, '_absent-private', 'skipped'],
               [3, 'b2-passing', 'passed'],
            ])
            ->assert();

         yield (new Assertion(description: 'the absent private case is skipped, never silently dropped'))
            ->expect([$Private->passed, $Private->failed, $Private->skipped])
            ->to->be([2, 0, 1])
            ->assert();
      }
      finally {
         Suite::$exitOnFailure = $exitOnFailure;
         Suite::$quiet = $quiet;
         Results::$enabled = $enabled;

         array_map('unlink', glob("{$directory}/*.Test.php") ?: []);
         @rmdir($directory);
      }
   })
);
