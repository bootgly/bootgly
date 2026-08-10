<?php


use const Bootgly\WPI;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Workables\Server\Middleware;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;


return new Test(
   description: 'It should serve immutable static responses without bypassing routing policy or state',
   test: new Assertions(Case: function (): Generator {
      $Router = new Router;
      $Middleware = new class implements Middleware {
         public int $calls = 0;

         public function process (object $Request, object $Response, Closure $Next): object
         {
            $this->calls++;
            if ($Response instanceof Response) {
               $Response->code(418);
            }

            $Result = $Next($Request, $Response);
            if ($Result instanceof Response) {
               $Result->Body->raw .= '|middleware';
            }

            return $Result;
         }
      };
      $GroupMiddleware = new class implements Middleware {
         public int $calls = 0;

         public function process (object $Request, object $Response, Closure $Next): object
         {
            $this->calls++;
            return $Next($Request, $Response);
         }
      };

      $registration = $Router->serve('/', 'root', 'GET');
      $Router->serve('/plain', 'plain', 'GET');
      $Router->serve('/empty', '', 'GET');
      $Router->serve('/agnostic', 'agnostic');
      $Router->serve('/head', 'head', 'HEAD');
      $Router->serve('/guarded', 'guarded', 'GET', [$Middleware]);
      $Router->serve('/dynamic/:id', 'dynamic', 'GET');

      // ! The shared cache tables preserve normal last-registration-wins
      //   behavior across route() and serve() in both directions.
      $Router->route('/route-then-serve', static function (
         Request $Request,
         Response $Response
      ): Response {
         return $Response(body: 'route');
      }, 'GET');
      $Router->serve('/route-then-serve', 'serve', 'GET');

      $Router->serve('/serve-then-route', 'serve', 'GET');
      $Router->route('/serve-then-route', static function (
         Request $Request,
         Response $Response
      ): Response {
         return $Response(body: 'route');
      }, 'GET');

      // ! Group prefix, inherited method and intercepted middleware must all
      //   force the ordinary dispatcher path.
      $Router->route('/group/:*', function () use ($Router, $GroupMiddleware): Generator {
         $Router->intercept($GroupMiddleware);
         yield $Router->serve('item', 'group');
      }, 'GET');

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

      $Resolve = static function (
         string $URI,
         string $method = 'GET',
         null|Response $Response = null
      ) use ($Router, $Request, $URIProperty, $URLProperty, $WPI): array {
         $Request->method = $method;
         $URIProperty->setValue($Request, $URI);
         $URLProperty->setValue($Request, null);
         $Response ??= new Response;
         $WPI->Response = $Response;

         return [$Router->resolve(), $Response];
      };

      try {
         [$RootResult, $RootResponse] = $Resolve('/');
         [$PlainResult, $PlainResponse] = $Resolve('/plain');
         [$EmptyResult, $EmptyResponse] = $Resolve('/empty');
         [$AgnosticResult, $AgnosticResponse] = $Resolve('/agnostic', 'DELETE');
         [$HeadResult, $HeadResponse] = $Resolve('/head', 'HEAD');
         [$MethodMiss] = $Resolve('/plain', 'POST');
         [$GuardedResult, $GuardedResponse] = $Resolve('/guarded');
         [$FirstDynamic] = $Resolve('/dynamic/42');
         $dynamicID = $Router->Route->Params->id;
         [$AfterDynamic, $AfterDynamicResponse] = $Resolve('/plain');
         $paramsScrubbed = $Router->Route->Params->id === null;
         $staticPath = $Router->Route->path;
         [$GroupResult, $GroupResponse] = $Resolve('/group/item');
         [$RouteThenServe, $RouteThenServeResponse] = $Resolve('/route-then-serve');
         [$ServeThenRoute, $ServeThenRouteResponse] = $Resolve('/serve-then-route');

         $ChangedStatus = new Response;
         $ChangedStatus->code(418);
         [$StatusResult, $StatusResponse] = $Resolve('/plain', Response: $ChangedStatus);

         $Subtype = new class extends Response {
            public bool $invoked = false;

            public function __invoke (
               int $code = 200,
               array $headers = [],
               string $body = ''
            ): self {
               $this->invoked = true;
               return parent::__invoke($code, $headers, $body);
            }
         };
         [$SubtypeResult, $SubtypeResponse] = $Resolve('/plain', Response: $Subtype);

         // ? Warm routers ignore late registrations exactly like route().
         $Router->serve('/late', 'late', 'GET');
         [$LateResult] = $Resolve('/late');

         /** @var array<string,Closure|string> $RootCache */
         $RootCache = $RouterReflection->getProperty('RootCache')->getValue($Router);
         /** @var array<string,array<string,Closure|string>> $StaticCache */
         $StaticCache = $RouterReflection->getProperty('staticCache')->getValue($Router);

         yield new Assertion(description: 'serve() registers root, non-root, empty and agnostic bodies')
            ->expect(
               $registration === false
               && $RootResult === $RootResponse
               && $RootResponse->Body->raw === 'root'
               && $PlainResult === $PlainResponse
               && $PlainResponse->Body->raw === 'plain'
               && $EmptyResult === $EmptyResponse
               && $EmptyResponse->Body->raw === ''
               && $AgnosticResult === $AgnosticResponse
               && $AgnosticResponse->Body->raw === 'agnostic'
            )
            ->to->be(true)
            ->assert();

         yield new Assertion(description: 'serve() keeps method matching and the existing HEAD response path')
            ->expect(
               $HeadResult === $HeadResponse
               && $HeadResponse->Body->raw === 'head'
               && $MethodMiss === null
            )
            ->to->be(true)
            ->assert();

         yield new Assertion(description: 'route middleware remains authoritative for served responses')
            ->expect(
               $GuardedResult === $GuardedResponse
               && $GuardedResponse->code === 200
               && $GuardedResponse->Body->raw === 'guarded|middleware'
               && $Middleware->calls === 1
               && $StaticCache['GET']['/guarded'] instanceof Closure
            )
            ->to->be(true)
            ->assert();

         yield new Assertion(description: 'dynamic and grouped served routes retain Params, prefix and middleware behavior')
            ->expect(
               $FirstDynamic instanceof Response
               && $dynamicID === '42'
               && $AfterDynamic === $AfterDynamicResponse
               && $paramsScrubbed
               && $staticPath === '/plain'
               && $GroupResult === $GroupResponse
               && $GroupResponse->Body->raw === 'group'
               && $GroupMiddleware->calls === 1
            )
            ->to->be(true)
            ->assert();

         yield new Assertion(description: 'route()/serve() collisions remain last-registration-wins')
            ->expect(
               $RouteThenServe instanceof Response
               && $RouteThenServeResponse->Body->raw === 'serve'
               && $ServeThenRoute instanceof Response
               && $ServeThenRouteResponse->Body->raw === 'route'
            )
            ->to->be(true)
            ->assert();

         yield new Assertion(description: 'status changes and Response subtypes retain __invoke() semantics')
            ->expect(
               $StatusResult === $StatusResponse
               && $StatusResponse->code === 200
               && $StatusResponse->Body->raw === 'plain'
               && $SubtypeResult === $SubtypeResponse
               && $SubtypeResponse->Body->raw === 'plain'
               && $Subtype->invoked
            )
            ->to->be(true)
            ->assert();

         yield new Assertion(description: 'only middleware-free static bodies use the scalar fast entry')
            ->expect(
               $RootCache['GET'] === 'root'
               && $StaticCache['GET']['/plain'] === 'plain'
               && $StaticCache['GET']['/route-then-serve'] === 'serve'
               && $StaticCache['GET']['/serve-then-route'] instanceof Closure
               && $LateResult === null
            )
            ->to->be(true)
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

