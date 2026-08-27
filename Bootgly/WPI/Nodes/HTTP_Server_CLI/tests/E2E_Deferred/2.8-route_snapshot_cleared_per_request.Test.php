<?php

use const Bootgly\WPI;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Deferred\Boundary;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Deferred\Routes;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Idle\Client;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * BG-14: the middleware chain lives on the worker's Route only while its
 * dispatch runs. A middleware-free route dispatched on the SAME Router while
 * a guarded deferral is still parked must not inherit that chain — its throw
 * reaches the Catcher — while the parked generation keeps the chain its own
 * clone carries, and answers about the snapshot, not the live request.
 *
 * Test mode gives every request a fresh Router; this spec installs one
 * persistent Router (the Security 55.01 idiom) so the clear is observable.
 */
$Route = new Boundary('route');
$PersistentRouter = new Router;
$Probe = new class {
   /** @var array<string,mixed> */
   public array $plain = [];
   /** @var array<string,mixed> */
   public array $guarded = [];
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),

   request: static function (string $hostPort, int $testIndex) use ($Probe): string {
      try {
         // @ Park the guarded deferral; do not read yet
         $One = Client::open($hostPort);
         @fwrite($One, Client::request('/deferred/recover/persistent/guarded', $testIndex));
         usleep(150_000);
         // @ A middleware-free route on the same Router while it is parked
         $Two = Client::open($hostPort);
         $Probe->plain = Client::send($Two, Client::request('/deferred/recover/persistent/plain', $testIndex), 4.0);
         Client::close($Two);
         // @ Now the parked one
         $Probe->guarded = Client::send($One, '', 4.0);
         Client::close($One);
      }
      catch (Throwable $Throwable) {
         $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /deferred/ping HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: static function (Request $Request, Response $Response, Router $Router) use ($Route, $PersistentRouter): Response {
      // ! One Route across requests, as the production Encoder_ path has
      $WPI = WPI;
      $WPI->Router = $PersistentRouter;

      $PersistentRouter->route('/deferred/recover/persistent/guarded', static function (Request $Request, Response $Response) {
         return $Response->defer(static function (Response $Response): void {
            Routes::park($Response, 0.8);
            throw new RuntimeException('deferred-throw');
         });
      }, GET, [$Route]);
      $PersistentRouter->route('/deferred/recover/persistent/plain', static function (Request $Request, Response $Response) {
         return $Response->defer(static function (Response $Response): void {
            $Response->wait();
            throw new RuntimeException('deferred-throw');
         });
      }, GET);
      $PersistentRouter->route('/deferred/ping', static function (Request $Request, Response $Response) {
         return $Response(body: 'pong');
      }, GET);

      foreach ($PersistentRouter->routing() as $Routed) {
         if ($Routed instanceof Response) {
            return $Routed;
         }
      }

      return $Response(code: 404, body: 'not-routed');
   },

   test: new Assertions(Case: function (string $response) use ($Probe): Generator {
      $evidence = json_encode(['error' => $Probe->error, 'plain' => $Probe->plain, 'guarded' => $Probe->guarded]);
      $guarded = json_decode((string) ($Probe->guarded['body'] ?? ''), true);
      $guarded = is_array($guarded) ? $guarded : [];

      yield new Assertion(
         description: 'A middleware-free route does not inherit the chain of a parked guarded route',
         fallback: "The previous route's boundary answered a middleware-free route: {$evidence}"
      )
         ->expect([$Probe->plain['code'] ?? 0, $Probe->plain['body'] ?? null])
         ->to->be([500, ' '])
         ->assert();

      yield new Assertion(
         description: 'The parked generation keeps the chain its clone carries',
         fallback: "The parked deferral lost its boundary: {$evidence}"
      )
         ->expect([
            $Probe->guarded['code'] ?? 0,
            stripos((string) ($Probe->guarded['head'] ?? ''), 'Content-Type: application/json') !== false,
            $guarded['recovered'] ?? null
         ])
         ->to->be([500, true, 'route'])
         ->assert();

      yield new Assertion(
         description: 'recover() received the request snapshot, not the live per-connection request',
         fallback: "Unexpected request in recover(): {$evidence}"
      )
         ->expect($guarded['URI'] ?? null)
         ->to->be('/deferred/recover/persistent/guarded')
         ->assert();
   })
);
