<?php

use Closure;
use Generator;
use stdClass;

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Server\Middleware;
use Bootgly\API\Server\Middlewares;


return new Specification(
   description: 'It should support pipe() with multiple middlewares at once',
   test: new Assertions(Case: function (): Generator {
      // !
      $log = [];

      $MiddlewareA = new class ($log) implements Middleware {
         /** @var array<string> */
         private array $log;
         /** @param array<string> $log */
         public function __construct (array &$log)
         {
            $this->log = &$log;
         }

         public function process (object $Request, object $Response, Closure $next): object
         {
            $this->log[] = 'A';

            return $next($Request, $Response);
         }
      };
      $MiddlewareB = new class ($log) implements Middleware {
         /** @var array<string> */
         private array $log;
         /** @param array<string> $log */
         public function __construct (array &$log)
         {
            $this->log = &$log;
         }

         public function process (object $Request, object $Response, Closure $next): object
         {
            $this->log[] = 'B';

            return $next($Request, $Response);
         }
      };
      $MiddlewareC = new class ($log) implements Middleware {
         /** @var array<string> */
         private array $log;
         /** @param array<string> $log */
         public function __construct (array &$log)
         {
            $this->log = &$log;
         }

         public function process (object $Request, object $Response, Closure $next): object
         {
            $this->log[] = 'C';

            return $next($Request, $Response);
         }
      };

      $Pipeline = new Middlewares;
      $Pipeline->pipe($MiddlewareA, $MiddlewareB, $MiddlewareC);

      // @
      $Pipeline->process(
         new stdClass,
         new stdClass,
         function (object $Request, object $Response) use (&$log): object {
            $log[] = 'handler';

            return $Response;
         }
      );

      // :
      yield new Assertion(
         description: 'All three middlewares should execute in order before handler',
      )
         ->expect($log)
         ->to->be(['A', 'B', 'C', 'handler'])
         ->assert();
   })
);
