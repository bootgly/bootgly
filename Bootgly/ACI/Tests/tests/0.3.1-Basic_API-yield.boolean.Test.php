<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'It should assert returning true (with yield)',
   test: function (): Generator
   {
      yield true === true;

      Assertion::$description = 'Asserting that true is not false';
      yield true !== false;

      $framework = 'Bootgly';
      if ($framework !== 'Bootgly') {
         yield "Framework is not Bootgly!";
      }
      else {
         Assertion::$description = 'Asserting that framework is Bootgly';
         yield true;
      }
   }
);
