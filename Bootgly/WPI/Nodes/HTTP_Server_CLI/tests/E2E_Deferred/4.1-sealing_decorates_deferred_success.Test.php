<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middleware;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Deferred\Sealer;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Idle\Client;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * BG-22: the post-`$next()` half of the onion never runs against a deferred
 * response — the clone was captured inside the handler. A middleware that
 * also implements `Sealing` is offered the Response at settlement, with the
 * real outcome in place: the stamp carries the status the seal saw. The
 * route chain AND the global pipeline are walked; the plain post-`$next()`
 * header states the honest limit (the pass is opt-in, like `Recovering`),
 * and the synchronous twin holds the same chain to parity.
 */
$RouteSealer = new Sealer('route');
$GlobalSealer = new Sealer('global', prefix: '/deferred/seal/ok');
$AfterPlain = new class implements Middleware {
   public function process (object $Request, object $Response, Closure $next): object
   {
      $Result = $next($Request, $Response);
      $Result->Header->set('X-After-Plain', '1'); // @phpstan-ignore-line
      return $Result;
   }
};
$Probe = new class {
   /** @var array<string,mixed> */
   public array $deferred = [];
   /** @var array<string,mixed> */
   public array $sync = [];
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),

   middlewares: [$GlobalSealer],

   request: static function (string $hostPort, int $testIndex) use ($Probe): string {
      try {
         $Socket = Client::open($hostPort);
         $Probe->deferred = Client::send($Socket, Client::request('/deferred/seal/ok', $testIndex), 4.0);
         $Probe->sync = Client::send($Socket, Client::request('/sync/seal/ok', $testIndex), 4.0);
         Client::close($Socket);
      }
      catch (Throwable $Throwable) {
         $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /deferred/ping HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },

   response: static function (Request $Request, Response $Response, Router $Router) use ($RouteSealer, $AfterPlain): Generator {
      yield $Router->route('/deferred/seal/ok', static function (Request $Request, Response $Response) {
         return $Response->defer(static function (Response $Response): void {
            $Response->wait();
            $Response->JSON->send(['ok' => 'deferred']);
         });
      }, GET, [$AfterPlain, $RouteSealer]);

      yield $Router->route('/sync/seal/ok', static function (Request $Request, Response $Response) {
         return $Response->JSON->send(['ok' => 'sync']);
      }, GET, [$AfterPlain, $RouteSealer]);

      yield $Router->route('/deferred/ping', static function (Request $Request, Response $Response) {
         return $Response(body: 'pong');
      }, GET);
   },

   test: new Assertions(Case: function (string $response) use ($Probe): Generator {
      $evidence = json_encode([
         'error' => $Probe->error,
         'deferred' => $Probe->deferred['head'] ?? null,
         'sync' => $Probe->sync['head'] ?? null
      ]);

      yield new Assertion(
         description: 'The deferred wire carries the route seal, stamped with the REAL status',
         fallback: "X-Sealed-route missing or wrong: {$evidence}"
      )
         ->expect(str_contains($Probe->deferred['head'] ?? '', 'X-Sealed-route: 200'))
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'The global pipeline is sealed too',
         fallback: "X-Sealed-global missing: {$evidence}"
      )
         ->expect(str_contains($Probe->deferred['head'] ?? '', 'X-Sealed-global: 200'))
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'The honest limit stands: a plain post-`$next()` header stays lost on deferred',
         fallback: "X-After-Plain leaked into the deferred wire: {$evidence}"
      )
         ->expect(str_contains($Probe->deferred['head'] ?? '', 'X-After-Plain'))
         ->to->be(false)
         ->assert();

      yield new Assertion(
         description: 'The synchronous twin carries both — parity through one chain',
         fallback: "sync twin wire diverged: {$evidence}"
      )
         ->expect(
            str_contains($Probe->sync['head'] ?? '', 'X-Sealed-route: 200')
            && str_contains($Probe->sync['head'] ?? '', 'X-After-Plain: 1')
         )
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'The keep-alive connection still answers',
         fallback: "final ping failed: {$response}"
      )
         ->expect(str_starts_with($response, 'HTTP/1.1 200'))
         ->to->be(true)
         ->assert();
   })
);
