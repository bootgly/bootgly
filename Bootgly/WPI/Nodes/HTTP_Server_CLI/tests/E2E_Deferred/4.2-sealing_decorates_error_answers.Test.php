<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Deferred\Boundary;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Deferred\Sealer;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Idle\Client;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * BG-22, errored path: the sealing pass decorates the answer actually chosen
 * for the wire — a `Recovering` boundary's or the Catcher's — the way the
 * synchronous unwind decorates a response returning through the outer
 * middleware. A seal that throws there is contained: the chosen answer is
 * never forfeited for a decorator, and the connection lives on.
 */
$Sealed = new Sealer('route');
$Lone = new Sealer('lone');
$Thrower = new Sealer('bad', mode: 'throw');
$Probe = new class {
   /** @var array<string,mixed> */
   public array $recovered = [];
   /** @var array<string,mixed> */
   public array $caught = [];
   /** @var array<string,mixed> */
   public array $contained = [];
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),

   request: static function (string $hostPort, int $testIndex) use ($Probe): string {
      try {
         $Socket = Client::open($hostPort);
         $Probe->recovered = Client::send($Socket, Client::request('/deferred/seal/recovered', $testIndex), 4.0);
         $Probe->contained = Client::send($Socket, Client::request('/deferred/seal/contained', $testIndex), 4.0);
         // ! Last on the connection: the Catcher's answer may close it
         $Probe->caught = Client::send($Socket, Client::request('/deferred/seal/caught', $testIndex), 4.0);
         Client::close($Socket);
      }
      catch (Throwable $Throwable) {
         $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /deferred/ping HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },

   response: static function (Request $Request, Response $Response, Router $Router) use ($Sealed, $Lone, $Thrower): Generator {
      // @ A boundary answers; the seal decorates its 500 in place
      yield $Router->route('/deferred/seal/recovered', static function (Request $Request, Response $Response) {
         return $Response->defer(static function (Response $Response): void {
            $Response->wait();
            throw new RuntimeException('deferred-throw');
         });
      }, GET, [new Boundary('route'), $Sealed]);

      // @ No boundary: the Catcher's answer is sealed as well
      yield $Router->route('/deferred/seal/caught', static function (Request $Request, Response $Response) {
         return $Response->defer(static function (Response $Response): void {
            $Response->wait();
            throw new RuntimeException('deferred-throw');
         });
      }, GET, [$Lone]);

      // @ A throwing seal is contained — the boundary's answer still lands
      yield $Router->route('/deferred/seal/contained', static function (Request $Request, Response $Response) {
         return $Response->defer(static function (Response $Response): void {
            $Response->wait();
            throw new RuntimeException('deferred-throw');
         });
      }, GET, [new Boundary('b2'), $Thrower]);

      yield $Router->route('/deferred/ping', static function (Request $Request, Response $Response) {
         return $Response(body: 'pong');
      }, GET);
   },

   test: new Assertions(Case: function (string $response) use ($Probe): Generator {
      $evidence = json_encode([
         'error' => $Probe->error,
         'recovered' => $Probe->recovered['head'] ?? null,
         'caught' => $Probe->caught['head'] ?? null,
         'contained' => [$Probe->contained['code'] ?? 0, $Probe->contained['head'] ?? null]
      ]);

      yield new Assertion(
         description: 'The boundary\'s 500 reaches the wire sealed, stamped with ITS status',
         fallback: "boundary answer not sealed: {$evidence}"
      )
         ->expect(
            ($Probe->recovered['code'] ?? 0) === 500
            && str_contains($Probe->recovered['head'] ?? '', 'X-Sealed-route: 500')
            && str_contains($Probe->recovered['body'] ?? '', '"recovered":"route"')
         )
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'The Catcher\'s answer is sealed too',
         fallback: "Catcher answer not sealed: {$evidence}"
      )
         ->expect(
            ($Probe->caught['code'] ?? 0) === 500
            && str_contains($Probe->caught['head'] ?? '', 'X-Sealed-lone: 500')
         )
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'A throwing seal is contained: the chosen answer lands, unstamped',
         fallback: "contained leg diverged: {$evidence}"
      )
         ->expect(
            ($Probe->contained['code'] ?? 0) === 500
            && str_contains($Probe->contained['body'] ?? '', '"recovered":"b2"')
            && str_contains($Probe->contained['head'] ?? '', 'X-Sealed-bad') === false
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
