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
   description: 'It should execute a single middleware around the handler',
   test: new Assertions(Case: function (): Generator {
      // !
      $log = [];

      $Middleware = new class ($log) implements Middleware {
         /** @var array<string> */
         private array $log;
         /** @param array<string> $log */
         public function __construct (array &$log)
         {
            $this->log = &$log;
         }

         public function process (object $Request, object $Response, Closure $next): object
         {
            $this->log[] = 'before';
            $Response = $next($Request, $Response);
            $this->log[] = 'after';

            return $Response;
         }
      };

      $Pipeline = new Middlewares;
      $Pipeline->pipe($Middleware);

      // @
      $Result = $Pipeline->process(
         new stdClass,
         new stdClass,
         function (object $Request, object $Response) use (&$log): object {
            $log[] = 'handler';
            return $Response;
         }
      );

      // :
      yield new Assertion(
         description: 'Middleware should execute before and after handler',
      )
         ->expect($log)
         ->to->be(['before', 'handler', 'after'])
         ->assert();

      yield new Assertion(
         description: 'Result should be the Response object',
      )
         ->expect($Result instanceof stdClass) // @phpstan-ignore-line
         ->to->be(true)
         ->assert();
   })
);