<?php

use Generator;
use stdClass;

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Workables\Server\Middlewares;


return new Specification(
   description: 'It should call handler directly when stack is empty',
   test: new Assertions(Case: function (): Generator {
      // !
      $Pipeline = new Middlewares;
      $called = false;

      // @
      $Result = $Pipeline->process(
         new stdClass,
         new stdClass,
         function (object $Request, object $Response) use (&$called): object {
            $called = true;
            $Response->handled = true; // @phpstan-ignore-line
            return $Response;
         }
      );

      // :
      yield new Assertion(
         description: 'Handler should be called when stack is empty',
      )
         ->expect($called) // @phpstan-ignore-line
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'Result should be an object',
      )
         ->expect($Result instanceof stdClass) // @phpstan-ignore-line
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'Handler should have set handled property',
      )
         ->expect($Result->handled) // @phpstan-ignore-line
         ->to->be(true)
         ->assert();
   })
);