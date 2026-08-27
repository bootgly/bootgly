<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middleware;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Deferred\Boundary;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Deferred\Routes;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Idle\Client;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * BG-14: the route's chain is published on the Route BEFORE the first
 * `process()` runs — a middleware that defers ahead of `$next` (and never
 * calls it) takes its clone while the chain is already there, so the
 * boundary outside it still answers the deferred throw. A chain published
 * only around the handler would leave that deferral with no boundary.
 */
$Deferring = new class implements Middleware {
   public function process (object $Request, object $Response, Closure $next): object
   {
      // ! Defers ahead of $next and never calls it
      if ($Response instanceof Response) {
         return $Response->defer(static function (Response $Response): void {
            $Response->wait();
            throw new RuntimeException('deferred-throw');
         });
      }

      return $next($Request, $Response);
   }
};
$Route = new Boundary('route');
$Probe = new class {
   /** @var array<string,mixed> */
   public array $ahead = [];
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),

   request: static function (string $hostPort, int $testIndex) use ($Probe): string {
      try {
         $Socket = Client::open($hostPort);
         $Probe->ahead = Client::send($Socket, Client::request('/deferred/recover/ahead', $testIndex), 4.0);
         Client::close($Socket);
      }
      catch (Throwable $Throwable) {
         $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /deferred/ping HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: static function (Request $Request, Response $Response, Router $Router) use ($Route, $Deferring): Generator {
      // @ The boundary is OUTSIDE the deferring middleware; the handler never runs
      yield $Router->route('/deferred/recover/ahead', static function (Request $Request, Response $Response) {
         return $Response(body: 'never');
      }, GET, [$Route, $Deferring]);
      yield from Routes::mount($Router);
   },

   test: new Assertions(Case: function (string $response) use ($Probe): Generator {
      $evidence = json_encode(['error' => $Probe->error, 'ahead' => $Probe->ahead]);
      $ahead = json_decode((string) ($Probe->ahead['body'] ?? ''), true);
      $ahead = is_array($ahead) ? $ahead : [];

      yield new Assertion(
         description: 'A deferral started by a middleware ahead of $next is still answered by the boundary outside it',
         fallback: "The chain was not published before the first process(): {$evidence}"
      )
         ->expect([
            $Probe->ahead['code'] ?? 0,
            stripos((string) ($Probe->ahead['head'] ?? ''), 'Content-Type: application/json') !== false,
            $ahead['recovered'] ?? null,
            $ahead['throwable'] ?? null,
            $ahead['message'] ?? null
         ])
         ->to->be([500, true, 'route', RuntimeException::class, 'deferred-throw'])
         ->assert();
   })
);
