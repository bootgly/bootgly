<?php

use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Workables\Client as CAPI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test as E2ETest;


return new Test(
   description: 'It should fail closed in pretest(): a missing spec aborts the run',
   test: function () {
      // ! Save the runner's global state touched by probe Suites and pretest()
      $exitOnFailure = Suite::$exitOnFailure;
      $quiet = Suite::$quiet;
      $count = Assertions::$count;
      $Runner = CAPI::$Suite ?? null;
      $hadSlots = isset(CAPI::$Tests[HTTP_Client_CLI::class]);
      $Slots = CAPI::$Tests[HTTP_Client_CLI::class] ?? [];
      $hadFiles = isset(CAPI::$tests[HTTP_Client_CLI::class]);
      $files = CAPI::$tests[HTTP_Client_CLI::class] ?? [];

      // ! Probe — run pretest() against the real E2E spec dir, capture the outcome
      $probe = static function (array $tests): array {
         $Probe = new Suite(
            autoBoot: static function (): true { return true; },
            suiteName: 'Pretest probe',
            tests: $tests
         );

         try {
            HTTP_Client_CLI::pretest($Probe);
         }
         catch (Throwable $Throwable) {
            return [$Throwable, $Probe];
         }

         // :
         return [null, $Probe];
      };

      // @ Collect outcomes first; assert only after globals are restored
      [$Missing] = $probe(['Missing/9.9-does_not_exist']);
      [$Private, $PrivateProbe] = $probe(['_9.8-private_absent']);
      $privateSlots = count(CAPI::$Tests[HTTP_Client_CLI::class]);
      [$Valid, $ValidProbe] = $probe(['Connection/5.1-basic_get']);
      $ValidSlot = CAPI::$Tests[HTTP_Client_CLI::class][0] ?? null;

      // ! Restore the runner's global state
      Suite::$exitOnFailure = $exitOnFailure;
      Suite::$quiet = $quiet;
      Assertions::$count = $count;
      if ($Runner !== null) {
         CAPI::$Suite = $Runner;
      }
      if ($hadSlots) {
         CAPI::$Tests[HTTP_Client_CLI::class] = $Slots;
      }
      else {
         unset(CAPI::$Tests[HTTP_Client_CLI::class]);
      }
      if ($hadFiles) {
         CAPI::$tests[HTTP_Client_CLI::class] = $files;
      }
      else {
         unset(CAPI::$tests[HTTP_Client_CLI::class]);
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
         assertion: $Valid === null
            && $ValidSlot instanceof E2ETest
            && count($ValidProbe->tests) === 1,
         description: 'Valid spec still loads: ' . ($ValidSlot !== null ? $ValidSlot::class : 'EMPTY SLOT')
      );
   }
);
