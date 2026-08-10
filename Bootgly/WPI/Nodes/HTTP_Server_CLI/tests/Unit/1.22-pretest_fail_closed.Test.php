<?php

use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Workables\Server as SAPI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test as E2ETest;


return new Test(
   description: 'It should fail closed in pretest(): missing or invalid specs abort the run',
   test: function () {
      // ! Save the runner's global state touched by probe Suites and pretest()
      $exitOnFailure = Suite::$exitOnFailure;
      $quiet = Suite::$quiet;
      $count = Assertions::$count;
      $Runner = SAPI::$Suite ?? null;
      $hadSlots = isset(SAPI::$Tests[HTTP_Server_CLI::class]);
      $Slots = SAPI::$Tests[HTTP_Server_CLI::class] ?? [];
      $hadFiles = isset(SAPI::$tests[HTTP_Server_CLI::class]);
      $files = SAPI::$tests[HTTP_Server_CLI::class] ?? [];

      // ! Probe — run pretest() against the fixtures dir, capture the outcome
      $fixtures = __DIR__ . '/fixtures';
      $probe = static function (array $tests) use ($fixtures): array {
         $Probe = new Suite(
            autoBoot: static function (): true { return true; },
            suiteName: 'Pretest probe',
            tests: $tests
         );

         try {
            HTTP_Server_CLI::pretest($Probe, specs: $fixtures);
         }
         catch (Throwable $Throwable) {
            return [$Throwable, $Probe];
         }

         // :
         return [null, $Probe];
      };

      // @ Collect outcomes first; assert only after globals are restored
      [$Missing] = $probe(['9.9-does_not_exist']);
      [$Private, $PrivateProbe] = $probe(['_9.8-private_absent']);
      $privateSlots = count(SAPI::$Tests[HTTP_Server_CLI::class]);
      [$Invalid] = $probe(['bad-return']);
      [$Valid, $ValidProbe] = $probe(['valid']);
      $ValidSlot = SAPI::$Tests[HTTP_Server_CLI::class][0] ?? null;

      // ! Restore the runner's global state
      Suite::$exitOnFailure = $exitOnFailure;
      Suite::$quiet = $quiet;
      Assertions::$count = $count;
      if ($Runner !== null) {
         SAPI::$Suite = $Runner;
      }
      if ($hadSlots) {
         SAPI::$Tests[HTTP_Server_CLI::class] = $Slots;
      }
      else {
         unset(SAPI::$Tests[HTTP_Server_CLI::class]);
      }
      if ($hadFiles) {
         SAPI::$tests[HTTP_Server_CLI::class] = $files;
      }
      else {
         unset(SAPI::$tests[HTTP_Server_CLI::class]);
      }

      yield assert(
         assertion: $Missing instanceof Exception
            && str_contains($Missing->getMessage(), 'Test case not found'),
         description: 'Missing spec aborts: ' . ($Missing !== null ? $Missing->getMessage() : 'NO THROW')
      );

      yield assert(
         assertion: $Private === null && $privateSlots === 0 && $PrivateProbe->tests === [],
         description: "_-prefixed absent spec stays private: slots={$privateSlots}"
      );

      yield assert(
         assertion: $Invalid instanceof Exception
            && str_contains($Invalid->getMessage(), 'must return a Test instance'),
         description: 'Non-Test return aborts: ' . ($Invalid !== null ? $Invalid->getMessage() : 'NO THROW')
      );

      yield assert(
         assertion: $Valid === null
            && $ValidSlot instanceof E2ETest
            && count($ValidProbe->tests) === 1,
         description: 'Valid spec still loads: ' . ($ValidSlot !== null ? $ValidSlot::class : 'EMPTY SLOT')
      );
   }
);
