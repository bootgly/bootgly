<?php

use Generator;

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should test using throwers',
   test: new Assertions(Case: function (): Generator
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
);
