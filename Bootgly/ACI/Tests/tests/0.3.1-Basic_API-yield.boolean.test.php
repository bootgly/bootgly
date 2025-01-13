<?php

use Generator;
use Bootgly\ACI\Tests\Assertion;

return [
   // @ configure
   'describe' => 'It should assert returning true (with yield)',
   // @ simulate
   // ...
   // @ test
   'test' => function (): Generator
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
];
