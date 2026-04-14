<?php

use Closure;
use Generator;
use stdClass;

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Workables\Server\Middleware;
use Bootgly\API\Workables\Server\Middlewares;


return new Specification(
   description: 'It should short-circuit when middleware does not call $next',
   test: new Assertions(Case: function (): Generator {
      // !
      $handlerCalled = false;

      $Blocker = new class implements Middleware {
         public function process (object $Request, object $Response, Closure $next): object
         {
            // ? Short-circuit: do NOT call $next
            $Response->blocked = true; // @phpstan-ignore-line

            return $Response;
         }
      };

      $Pipeline = new Middlewares;
      $Pipeline->pipe($Blocker);

      // @
      $Result = $Pipeline->process(
         new stdClass,
         new stdClass,
         function (object $Request, object $Response) use (&$handlerCalled): object {
            $handlerCalled = true;

            return $Response;
         }
      );

      // :
      yield new Assertion(
         description: 'Handler should NOT be called when middleware short-circuits',
      )
         ->expect($handlerCalled) // @phpstan-ignore-line
         ->to->be(false)
         ->assert();

      yield new Assertion(
         description: 'Response should have blocked property from middleware',
      )
         ->expect($Result->blocked) // @phpstan-ignore-line
         ->to->be(true)
         ->assert();
   })
);