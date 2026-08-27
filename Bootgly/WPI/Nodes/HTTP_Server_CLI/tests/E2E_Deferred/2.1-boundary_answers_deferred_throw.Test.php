<?php

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
 * BG-14: a Throwable raised inside deferred work is offered to the route's
 * `Recovering` middleware — whether the work threw after a `wait()` or before
 * any suspension at all — and the boundary's answer is what reaches the wire.
 * A route without a boundary keeps the Catcher's answer (byte-exact in Test).
 */
$Route = new Boundary('route');
$Probe = new class {
   /** @var array<string,mixed> */
   public array $after = [];
   /** @var array<string,mixed> */
   public array $before = [];
   /** @var array<string,mixed> */
   public array $none = [];
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),

   request: static function (string $hostPort, int $testIndex) use ($Probe): string {
      try {
         $Socket = Client::open($hostPort);
         $Probe->after = Client::send($Socket, Client::request('/deferred/recover/after', $testIndex), 4.0);
         $Probe->before = Client::send($Socket, Client::request('/deferred/recover/before', $testIndex), 4.0);
         // ! Last on the connection: the Catcher's answer may close it
         $Probe->none = Client::send($Socket, Client::request('/deferred/recover/none', $testIndex), 4.0);
         Client::close($Socket);
      }
      catch (Throwable $Throwable) {
         $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /deferred/ping HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: static function (Request $Request, Response $Response, Router $Router) use ($Route): Generator {
      // @ The work throws after its first suspension
      yield $Router->route('/deferred/recover/after', static function (Request $Request, Response $Response) {
         return $Response->defer(static function (Response $Response): void {
            $Response->wait();
            throw new RuntimeException('deferred-throw');
         });
      }, GET, [$Route]);
      // @ The work throws BEFORE any wait(): the inline first segment, still
      //   inside defer() on the synchronous cycle
      yield $Router->route('/deferred/recover/before', static function (Request $Request, Response $Response) {
         return $Response->defer(static function (Response $Response): void {
            throw new RuntimeException('deferred-throw');
         });
      }, GET, [$Route]);
      // @ No boundary at all: the Catcher answers
      yield $Router->route('/deferred/recover/none', static function (Request $Request, Response $Response) {
         return $Response->defer(static function (Response $Response): void {
            $Response->wait();
            throw new RuntimeException('deferred-throw');
         });
      }, GET);
      yield from Routes::mount($Router);
   },

   test: new Assertions(Case: function (string $response) use ($Probe): Generator {
      $evidence = json_encode([
         'error' => $Probe->error,
         'after' => $Probe->after,
         'before' => $Probe->before,
         'none' => $Probe->none
      ]);
      $type = static function (array $exchange): string {
         $matches = [];
         return preg_match('/\r\nContent-Type:[ \t]*([^\r\n]+)/i', (string) ($exchange['head'] ?? ''), $matches) === 1
            ? trim($matches[1])
            : '';
      };
      $decode = static function (array $exchange): array {
         $decoded = json_decode((string) ($exchange['body'] ?? ''), true);
         return is_array($decoded) ? $decoded : [];
      };
      $after = $decode($Probe->after);
      $before = $decode($Probe->before);

      yield new Assertion(
         description: 'The boundary answers a throw raised after the first wait()',
         fallback: "The boundary did not answer the parked throw: {$evidence}"
      )
         ->expect([
            $Probe->after['code'] ?? 0,
            str_starts_with($type($Probe->after), 'application/json'),
            $after['recovered'] ?? null,
            $after['throwable'] ?? null,
            $after['message'] ?? null
         ])
         ->to->be([500, true, 'route', RuntimeException::class, 'deferred-throw'])
         ->assert();

      yield new Assertion(
         description: 'The boundary answers a throw raised before any wait()',
         fallback: "The boundary did not answer the inline throw: {$evidence}"
      )
         ->expect([
            $Probe->before['code'] ?? 0,
            str_starts_with($type($Probe->before), 'application/json'),
            $before['recovered'] ?? null,
            $before['throwable'] ?? null,
            $before['message'] ?? null
         ])
         ->to->be([500, true, 'route', RuntimeException::class, 'deferred-throw'])
         ->assert();

      yield new Assertion(
         description: 'recover() ran with the request snapshot bound as the ambient Request',
         fallback: "The boundary saw an unbound context: {$evidence}"
      )
         ->expect([$after['bound'] ?? null, $before['bound'] ?? null])
         ->to->be([true, true])
         ->assert();

      yield new Assertion(
         description: 'A route without a boundary keeps the Catcher answer, byte-exact',
         fallback: "The Catcher answer changed: {$evidence}"
      )
         ->expect([
            $Probe->none['code'] ?? 0,
            $Probe->none['body'] ?? null,
            str_starts_with($type($Probe->none), 'application/json')
         ])
         ->to->be([500, ' ', false])
         ->assert();
   })
);
