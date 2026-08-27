<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Timeout;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Deferred\Boundary;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Deferred\Routes;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Idle\Client;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * BG-14: the deferral budget (`Response\Timeout`) is offered to the boundaries
 * like any other Throwable — the boundary decides the representation. When
 * every boundary declines, the Catcher's 503 is unchanged.
 */
$Route = new Boundary('route');
$Pass = new Boundary('pass', mode: 'pass');
$Thrower = new Boundary('thrower', mode: 'throw');
$Probe = new class {
   /** @var array<string,mixed> */
   public array $timeout = [];
   /** @var array<string,mixed> */
   public array $passed = [];
   /** @var array<string,mixed> */
   public array $rethrown = [];
   /** @var array<string,mixed> */
   public array $before = [];
   /** @var array<string,mixed> */
   public array $after = [];
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),

   request: static function (string $hostPort, int $testIndex) use ($Probe): string {
      try {
         $Socket = Client::open($hostPort);
         $Probe->timeout = Client::send($Socket, Client::request('/deferred/recover/timeout', $testIndex), 4.0);
         Client::close($Socket);

         $Socket = Client::open($hostPort);
         $Probe->before = Client::send($Socket, Client::request('/deferred/recovered', $testIndex), 4.0);
         $Probe->passed = Client::send($Socket, Client::request('/deferred/recover/timeout/passed', $testIndex), 4.0);
         Client::close($Socket);
         $Socket = Client::open($hostPort);
         $Probe->after = Client::send($Socket, Client::request('/deferred/recovered', $testIndex), 4.0);
         Client::close($Socket);

         // @ A boundary that replaces the Timeout with another Throwable:
         //   the Catcher must answer the replacement (500), not the budget (503)
         $Socket = Client::open($hostPort);
         $Probe->rethrown = Client::send($Socket, Client::request('/deferred/recover/timeout/rethrown', $testIndex), 4.0);
         Client::close($Socket);
      }
      catch (Throwable $Throwable) {
         $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /deferred/ping HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: static function (Request $Request, Response $Response, Router $Router) use ($Route, $Pass, $Thrower): Generator {
      yield $Router->route('/deferred/recovered', static function (Request $Request, Response $Response) use ($Pass) {
         $Response->JSON->send(['pass' => $Pass->recoveries]);

         return $Response;
      }, GET);
      $work = static function (Response $Response): void {
         Routes::park($Response, 10.0);
         $Response->JSON->send(['parked' => true]);
      };
      yield $Router->route('/deferred/recover/timeout', static function (Request $Request, Response $Response) use ($work) {
         return $Response->defer($work, timeout: 0.5);
      }, GET, [$Route]);
      yield $Router->route('/deferred/recover/timeout/passed', static function (Request $Request, Response $Response) use ($work) {
         return $Response->defer($work, timeout: 0.5);
      }, GET, [$Pass]);
      yield $Router->route('/deferred/recover/timeout/rethrown', static function (Request $Request, Response $Response) use ($work) {
         return $Response->defer($work, timeout: 0.5);
      }, GET, [$Thrower]);
      yield from Routes::mount($Router);
   },

   test: new Assertions(Case: function (string $response) use ($Probe): Generator {
      $evidence = json_encode(['error' => $Probe->error, 'timeout' => $Probe->timeout, 'passed' => $Probe->passed, 'rethrown' => $Probe->rethrown, 'before' => $Probe->before, 'after' => $Probe->after]);
      $timeout = json_decode((string) ($Probe->timeout['body'] ?? ''), true);
      $timeout = is_array($timeout) ? $timeout : [];
      $before = json_decode((string) ($Probe->before['body'] ?? ''), true);
      $before = is_array($before) ? $before : [];
      $after = json_decode((string) ($Probe->after['body'] ?? ''), true);
      $after = is_array($after) ? $after : [];

      yield new Assertion(
         description: 'The boundary answers the deferral budget with its own 503',
         fallback: "The Timeout was not offered to the boundary: {$evidence}"
      )
         ->expect([
            $Probe->timeout['code'] ?? 0,
            stripos((string) ($Probe->timeout['head'] ?? ''), 'Content-Type: application/json') !== false,
            $timeout['recovered'] ?? null,
            $timeout['throwable'] ?? null,
            $timeout['timeout'] ?? null
         ])
         ->to->be([503, true, 'route', Timeout::class, 0.5])
         ->assert();

      yield new Assertion(
         description: 'The answer arrived at the budget',
         fallback: "Unexpected timing: {$evidence}"
      )
         ->expect($Probe->timeout['elapsed'] ?? 0.0)
         ->to->delimit(0.4, 1.9)
         ->assert();

      yield new Assertion(
         description: 'A Timeout the boundary was consulted about and declined keeps the Catcher 503',
         fallback: "The declined Timeout changed the Catcher answer, or the boundary was never consulted: {$evidence}"
      )
         ->expect([
            $Probe->passed['code'] ?? 0,
            $Probe->passed['body'] ?? null,
            (int) ($after['pass'] ?? 0) - (int) ($before['pass'] ?? 0)
         ])
         ->to->be([503, '', 1])
         ->assert();

      yield new Assertion(
         description: 'A Timeout replaced by a throwing boundary is answered as an application error, not as the budget',
         fallback: "The replaced Timeout was still answered as 503: {$evidence}"
      )
         ->expect([$Probe->rethrown['code'] ?? 0, $Probe->rethrown['body'] ?? null])
         ->to->be([500, ' '])
         ->assert();
   })
);
