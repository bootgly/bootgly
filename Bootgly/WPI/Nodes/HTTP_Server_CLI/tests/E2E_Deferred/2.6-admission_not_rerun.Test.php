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
 * BG-14: the walk consults `recover()` only — it never re-dispatches the
 * onion. An admission middleware in the same chain runs exactly once per
 * request, and a middleware that is not `Recovering` is skipped, not called.
 */
$Admission = new class implements Middleware {
   public int $runs = 0;

   public function process (object $Request, object $Response, Closure $next): object
   {
      $this->runs++;

      return $next($Request, $Response);
   }
};
$Route = new Boundary('route');
$Probe = new class {
   /** @var array<string,mixed> */
   public array $before = [];
   /** @var array<string,mixed> */
   public array $admitted = [];
   /** @var array<string,mixed> */
   public array $after = [];
   /** @var array<string,mixed> */
   public array $outer = [];
   /** @var array<string,mixed> */
   public array $last = [];
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),

   request: static function (string $hostPort, int $testIndex) use ($Probe): string {
      try {
         $Socket = Client::open($hostPort);
         $Probe->before = Client::send($Socket, Client::request('/deferred/recovered', $testIndex), 4.0);
         $Probe->admitted = Client::send($Socket, Client::request('/deferred/recover/admitted', $testIndex), 4.0);
         $Probe->after = Client::send($Socket, Client::request('/deferred/recovered', $testIndex), 4.0);
         // @ The boundary OUTSIDE the admission middleware: the walk meets
         //   the plain middleware first and must step over it
         $Probe->outer = Client::send($Socket, Client::request('/deferred/recover/admitted/outer', $testIndex), 4.0);
         $Probe->last = Client::send($Socket, Client::request('/deferred/recovered', $testIndex), 4.0);
         Client::close($Socket);
      }
      catch (Throwable $Throwable) {
         $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /deferred/ping HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: static function (Request $Request, Response $Response, Router $Router) use ($Admission, $Route): Generator {
      yield $Router->route('/deferred/recovered', static function (Request $Request, Response $Response) use ($Admission, $Route) {
         $Response->JSON->send([
            'admission' => $Admission->runs,
            'route_admissions' => $Route->admissions,
            'route_recoveries' => $Route->recoveries
         ]);

         return $Response;
      }, GET);
      yield $Router->route('/deferred/recover/admitted', static function (Request $Request, Response $Response) {
         return $Response->defer(static function (Response $Response): void {
            $Response->wait();
            throw new RuntimeException('deferred-throw');
         });
      }, GET, [$Admission, $Route]);
      yield $Router->route('/deferred/recover/admitted/outer', static function (Request $Request, Response $Response) {
         return $Response->defer(static function (Response $Response): void {
            $Response->wait();
            throw new RuntimeException('deferred-throw');
         });
      }, GET, [$Route, $Admission]);
      yield from Routes::mount($Router);
   },

   test: new Assertions(Case: function (string $response) use ($Probe): Generator {
      $evidence = json_encode([
         'error' => $Probe->error,
         'before' => $Probe->before,
         'admitted' => $Probe->admitted,
         'after' => $Probe->after,
         'outer' => $Probe->outer,
         'last' => $Probe->last
      ]);
      $decode = static function (array $exchange): array {
         $decoded = json_decode((string) ($exchange['body'] ?? ''), true);
         return is_array($decoded) ? $decoded : [];
      };
      $before = $decode($Probe->before);
      $admitted = $decode($Probe->admitted);
      $after = $decode($Probe->after);
      $outer = $decode($Probe->outer);
      $last = $decode($Probe->last);
      $delta = static fn (string $key): int => (int) ($after[$key] ?? 0) - (int) ($before[$key] ?? 0);
      $outerDelta = static fn (string $key): int => (int) ($last[$key] ?? 0) - (int) ($after[$key] ?? 0);

      yield new Assertion(
         description: 'A middleware that is not Recovering is skipped — never called — and the boundary answers the original Throwable',
         fallback: "Unexpected answer with an admission middleware in the chain: {$evidence}"
      )
         ->expect([
            $Probe->admitted['code'] ?? 0,
            $admitted['recovered'] ?? null,
            $admitted['throwable'] ?? null,
            $admitted['message'] ?? null
         ])
         ->to->be([500, 'route', RuntimeException::class, 'deferred-throw'])
         ->assert();

      yield new Assertion(
         description: 'Admission ran exactly once: the deferred throw re-dispatched nobody',
         fallback: "Admission was re-run for the deferred throw: {$evidence}"
      )
         ->expect([$delta('admission'), $delta('route_admissions'), $delta('route_recoveries')])
         ->to->be([1, 1, 1])
         ->assert();

      yield new Assertion(
         description: 'With the boundary outside the admission middleware, the walk steps over the plain one and the boundary still sees the original Throwable',
         fallback: "The plain middleware was not skipped cleanly: {$evidence}"
      )
         ->expect([
            $Probe->outer['code'] ?? 0,
            $outer['recovered'] ?? null,
            $outer['throwable'] ?? null,
            $outer['message'] ?? null,
            $outerDelta('admission'),
            $outerDelta('route_recoveries')
         ])
         ->to->be([500, 'route', RuntimeException::class, 'deferred-throw', 1, 1])
         ->assert();
   })
);
