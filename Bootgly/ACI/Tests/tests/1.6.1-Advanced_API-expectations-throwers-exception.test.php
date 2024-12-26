<?php

use Generator;

use Bootgly\ACI\Tests\Cases\Assertion;
use Bootgly\ACI\Tests\Cases\Assertions;

return [
   // @ configure
   'describe' => 'It should test using throwers',
   // @ simulate
   // ...
   // @ test
   'test' => new Assertions(Case: function (): Generator
   {
      // Exception
      $callable = function () {
         throw new Exception('Exception');
      };
      yield new Assertion(
         description: 'Validating exception',
      )
         ->expect($callable)
         ->to->call()
         ->to->throw(new Exception('Exception'))
         ->assert();
   })
];
