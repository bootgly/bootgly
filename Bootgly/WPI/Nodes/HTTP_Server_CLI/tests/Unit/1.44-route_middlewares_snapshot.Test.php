<?php


use const Bootgly\WPI;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Workables\Server\Middleware;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middleware as HTTPMiddleware;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Recovering;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Route;


/**
 * BG-14: the Router publishes the folded middleware chain on its Route for the
 * duration of a middleware-bearing dispatch only — written before the first
 * middleware runs, cleared on return and on throw — so a Route cloned by
 * `defer()` carries exactly the chain its route was dispatched with, and a
 * middleware-free dispatch never sees (or pays for) one.
 */
return new Test(
   description: 'It should carry the folded middleware chain on the Route only while a middleware-bearing dispatch runs',
   test: new Assertions(Case: function (): Generator {
      $Passthrough = new class implements Middleware {
         public function process (object $Request, object $Response, Closure $Next): object
         {
            return $Next($Request, $Response);
         }
      };
      $Group = new class implements Middleware {
         public function process (object $Request, object $Response, Closure $Next): object
         {
            return $Next($Request, $Response);
         }
      };
      $Throwing = new class implements Middleware {
         public function process (object $Request, object $Response, Closure $Next): object
         {
            throw new RuntimeException('middleware-throw');
         }
      };

      // @ The Route itself: a by-value list, cloned with the Route
      $Route = new Route;
      $emptyByDefault = $Route->Middlewares === [];
      $Route->Middlewares = [$Passthrough];
      $Clone = clone $Route;
      $Route->Middlewares = [];
      $cloneKeeps = $Clone->Middlewares === [$Passthrough]
         && $Route->Middlewares === []
         && $Clone->Params !== $Route->Params;

      // @ The Router: what a handler sees, what remains after the dispatch
      $Router = new Router;
      $Probe = new class {
         /** @var array<int,mixed>|string */
         public array|string $free = 'unset';
         /** @var array<int,mixed>|string */
         public array|string $guarded = 'unset';
         /** @var array<int,mixed>|string */
         public array|string $dynamic = 'unset';
         /** @var array<int,mixed>|string */
         public array|string $grouped = 'unset';
      };
      $free = static function (Request $Request, Response $Response): Response {
         return $Response(body: 'free');
      };
      $Router->route('/free', $free, 'GET');
      $Router->route('/free/probe', static function (Request $Request, Response $Response) use ($Router, $Probe): Response {
         $Probe->free = $Router->Route->Middlewares;
         return $Response(body: 'free');
      }, 'GET');
      $Router->route('/guarded', static function (Request $Request, Response $Response) use ($Router, $Probe): Response {
         $Probe->guarded = $Router->Route->Middlewares;
         return $Response(body: 'guarded');
      }, 'GET', [$Passthrough]);
      $Router->route('/throwing', static function (Request $Request, Response $Response): Response {
         return $Response(body: 'never');
      }, 'GET', [$Throwing]);
      $Router->route('/handler-throws', static function (Request $Request, Response $Response): Response {
         throw new RuntimeException('handler-throw');
      }, 'GET', [$Passthrough]);
      $Router->route('/dynamic/:id', static function (Request $Request, Response $Response) use ($Router, $Probe): Response {
         $Probe->dynamic = $Router->Route->Middlewares;
         return $Response(body: 'dynamic');
      }, 'GET', [$Passthrough]);
      // ! Non-static: the Router binds group handlers to its Route
      $Router->route('/group/:*', function () use ($Router, $Group, $Passthrough, $Probe): Generator {
         $Router->intercept($Group);
         yield $Router->route('item', static function (Request $Request, Response $Response) use ($Router, $Probe): Response {
            $Probe->grouped = $Router->Route->Middlewares;
            return $Response(body: 'grouped');
         }, null, [$Passthrough]);
      }, 'GET');
      $Router->serve('/served', 'served', 'GET');

      $RouterReflection = new ReflectionClass(Router::class);
      $RouterReflection->getMethod('flatten')->invoke($Router);
      $Router->cached = true;

      $WPI = WPI;
      $OldRequest = $WPI->Request ?? null;
      $OldResponse = $WPI->Response ?? null;
      $Request = new Request;
      $Request->protocol = 'HTTP/1.1';
      $URIProperty = new ReflectionProperty(Request::class, 'URI');
      $URLProperty = new ReflectionProperty(Request::class, '_URL');
      $WPI->Request = $Request;

      $Resolve = static function (string $URI) use ($Router, $Request, $URIProperty, $URLProperty, $WPI): mixed {
         $Request->method = 'GET';
         $URIProperty->setValue($Request, $URI);
         $URLProperty->setValue($Request, null);
         $WPI->Response = new Response;

         return $Router->resolve();
      };

      try {
         $Resolve('/guarded');
         $afterGuarded = $Router->Route->Middlewares;
         $Resolve('/free/probe');
         $afterFree = $Router->Route->Middlewares;

         $middlewareThrew = false;
         try {
            $Resolve('/throwing');
         }
         catch (RuntimeException $Thrown) {
            $middlewareThrew = $Thrown->getMessage() === 'middleware-throw';
         }
         $afterThrowing = $Router->Route->Middlewares;

         $handlerThrew = false;
         try {
            $Resolve('/handler-throws');
         }
         catch (RuntimeException $Thrown) {
            $handlerThrew = $Thrown->getMessage() === 'handler-throw';
         }
         $afterHandlerThrow = $Router->Route->Middlewares;

         $Resolve('/dynamic/7');
         $afterDynamic = $Router->Route->Middlewares;
         $Resolve('/group/item');
         $afterGrouped = $Router->Route->Middlewares;
         $Served = $Resolve('/served');
         $afterServed = $Router->Route->Middlewares;

         /** @var array<string,array<string,Closure|string>> $StaticCache */
         $StaticCache = $RouterReflection->getProperty('staticCache')->getValue($Router);

         yield new Assertion(description: 'A Route starts with no chain and a clone carries the chain by value')
            ->expect([$emptyByDefault, $cloneKeeps])
            ->to->be([true, true])
            ->assert();

         yield new Assertion(description: 'A middleware-bearing handler sees the folded chain, group entries first')
            ->expect([
               $Probe->guarded === [$Passthrough],
               $Probe->dynamic === [$Passthrough],
               $Probe->grouped === [$Group, $Passthrough]
            ])
            ->to->be([true, true, true])
            ->assert();

         yield new Assertion(description: 'The chain is gone once the dispatch returned — or threw, from a middleware or the handler')
            ->expect([
               $afterGuarded,
               $middlewareThrew,
               $afterThrowing,
               $handlerThrew,
               $afterHandlerThrow,
               $afterDynamic,
               $afterGrouped
            ])
            ->to->be([[], true, [], true, [], [], []])
            ->assert();

         yield new Assertion(description: 'A middleware-free dispatch never sees a chain and keeps its bare handler')
            ->expect([
               $Probe->free,
               $afterFree,
               $Served instanceof Response,
               $afterServed,
               $StaticCache['GET']['/free'] === $free,
               $StaticCache['GET']['/served'] === 'served'
            ])
            ->to->be([[], [], true, [], true, true])
            ->assert();

         $Recover = new ReflectionMethod(Recovering::class, 'recover');
         $parameters = [];
         foreach ($Recover->getParameters() as $Parameter) {
            $parameters[] = (string) $Parameter->getType();
         }

         yield new Assertion(description: 'Recovering is a Middleware whose recover() takes the snapshot, the clone and the Throwable')
            ->expect([
               new ReflectionClass(Recovering::class)->implementsInterface(HTTPMiddleware::class),
               $parameters,
               (string) $Recover->getReturnType()
            ])
            ->to->be([true, [Request::class, Response::class, Throwable::class], '?' . Response::class])
            ->assert();
      }
      finally {
         if ($OldRequest instanceof Request) {
            $WPI->Request = $OldRequest;
         }
         else {
            unset($WPI->Request);
         }

         if ($OldResponse instanceof Response) {
            $WPI->Response = $OldResponse;
         }
         else {
            unset($WPI->Response);
         }
      }
   })
);
