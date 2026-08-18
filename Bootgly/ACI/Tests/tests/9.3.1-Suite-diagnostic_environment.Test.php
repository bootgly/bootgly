<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Results;
use Bootgly\ACI\Tests\Suite;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Suite should run every case body at E_ALL and restore the mask afterwards',

   test: new Assertions(Case: function (): Generator {
      // ! Save the runner's global state
      $exitOnFailure = Suite::$exitOnFailure;
      $quiet = Suite::$quiet;
      $enabled = Results::$enabled;

      // ! A mask that is NOT E_ALL, so both halves are observable: the probe
      //   body must see E_ALL, and this body must get its own mask back.
      $outer = error_reporting(E_ALL & ~E_WARNING);

      $observed = [];

      try {
         Suite::$exitOnFailure = false;
         Suite::$quiet = true;
         Results::$enabled = false;

         $Suite = new Suite(
            tests: [],
            autoReport: true,
            exitOnFailure: false,
            suiteName: 'diagnostic environment probe'
         );

         $Probe = new Test(
            description: 'diagnostic probe',
            test: new Assertions(Case: function () use (&$observed): Generator {
               $observed['reporting'] = error_reporting();

               // @ An ENGINE deprecation (E_DEPRECATED), the class a stock
               //   php.ini masks — `error_reporting = E_ALL & ~E_DEPRECATED`.
               //   ABI's handler must turn it into an ErrorException.
               $observed['deprecated'] = 'not escalated';
               try {
                  /** @phpstan-ignore-next-line */
                  $length = strlen(null);
                  unset($length);
               }
               catch (Throwable $Throwable) {
                  $observed['deprecated'] = $Throwable::class;
               }

               // @ And the warning class the suite already relied on
               $observed['warning'] = 'not escalated';
               try {
                  $empty = [];
                  /** @phpstan-ignore-next-line */
                  $value = $empty['missing'];
                  unset($value);
               }
               catch (Throwable $Throwable) {
                  $observed['warning'] = $Throwable::class;
               }

               yield true;
            })
         );
         $Probe->index(case: 1, file: 'diagnostic-probe');
         $Suite->test($Probe)?->test();

         $restored = error_reporting();

         yield (new Assertion(description: 'a case body runs at E_ALL, not the host php.ini mask'))
            ->expect($observed['reporting'] ?? null)
            ->to->be(E_ALL)
            ->assert();

         yield (new Assertion(description: 'an engine deprecation reaches the handler inside a case'))
            ->expect($observed['deprecated'] ?? null)
            ->to->be(ErrorException::class)
            ->assert();

         yield (new Assertion(description: 'a warning still reaches the handler inside a case'))
            ->expect($observed['warning'] ?? null)
            ->to->be(ErrorException::class)
            ->assert();

         yield (new Assertion(description: 'the caller gets its own mask back after the case'))
            ->expect($restored)
            ->to->be(E_ALL & ~E_WARNING)
            ->assert();
      }
      finally {
         error_reporting($outer);

         Suite::$exitOnFailure = $exitOnFailure;
         Suite::$quiet = $quiet;
         Results::$enabled = $enabled;
      }
   })
);
