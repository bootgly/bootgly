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
   description: 'It should execute middlewares in onion order (A before → B before → handler → B after → A after)',
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
            $this->log[] = 'A-before';
            $Response = $next($Request, $Response);
            $this->log[] = 'A-after';

            return $Response;
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
            $this->log[] = 'B-before';
            $Response = $next($Request, $Response);
            $this->log[] = 'B-after';

            return $Response;
         }
      };

      $Pipeline = new Middlewares;
      $Pipeline->pipe($MiddlewareA, $MiddlewareB);

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
         description: 'Middlewares should execute in onion order',
      )
         ->expect($log)
         ->to->be(['A-before', 'B-before', 'handler', 'B-after', 'A-after'])
         ->assert();
   })
);