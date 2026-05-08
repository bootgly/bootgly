<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Coverage;
use Bootgly\ACI\Tests\Coverage\Drivers\XDebug;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Coverage — detect() enforces explicit XDebug coverage mode semantics',

   test: new Assertions(Case: function (): Generator {
      if (! extension_loaded('xdebug') && ! function_exists('xdebug_start_code_coverage')) {
         // Environment without xdebug: guard is not applicable.
         yield true;
         return;
      }

      $coverageModeEnabled = false;
      if (function_exists('xdebug_info')) {
         $info = 'xdebug_info';
         $modes = $info('mode');
         if (is_string($modes)) {
            $modes = explode(',', $modes);
         }

         if (is_array($modes)) {
            foreach ($modes as $mode) {
               if (trim((string) $mode) === 'coverage') {
                  $coverageModeEnabled = true;
                  break;
               }
            }
         }
      }

      if ($coverageModeEnabled) {
         $Driver = Coverage::detect();

         yield (new Assertion(description: 'detect() picks XDebug when coverage mode is enabled'))
            ->expect($Driver instanceof XDebug)
            ->to->be(true)
            ->assert();

         return;
      }

      $message = null;
      try {
         Coverage::detect();
      }
      catch (LogicException $Exception) {
         $message = $Exception->getMessage();
      }

      yield (new Assertion(description: 'detect() throws when xdebug exists without coverage mode'))
         ->expect($message !== null)
         ->to->be(true)
         ->assert();

      yield (new Assertion(description: 'error message explains missing coverage mode'))
         ->expect(str_contains(strtolower((string) $message), 'coverage'))
         ->to->be(true)
         ->assert();
   })
);
