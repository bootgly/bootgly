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
 * BG-14: the boundaries are walked innermost first — a route-level boundary
 * answers before the group's `intercept()` one, which is then never consulted
 * — and the snapshot a deferred generation carries is the MERGED chain (group
 * entries + route entries), so a nested route with no boundary of its own is
 * still answered by the group's.
 */
$Outer = new Boundary('outer');
$Inner = new Boundary('inner');
$Probe = new class {
   /** @var array<string,mixed> */
   public array $before = [];
   /** @var array<string,mixed> */
   public array $nested = [];
   /** @var array<string,mixed> */
   public array $middle = [];
   /** @var array<string,mixed> */
   public array $grouped = [];
   /** @var array<string,mixed> */
   public array $after = [];
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),

   request: static function (string $hostPort, int $testIndex) use ($Probe): string {
      try {
         $Socket = Client::open($hostPort);
         $Probe->before = Client::send($Socket, Client::request('/deferred/recovered', $testIndex), 4.0);
         $Probe->nested = Client::send($Socket, Client::request('/deferred/recover/nested', $testIndex), 4.0);
         $Probe->middle = Client::send($Socket, Client::request('/deferred/recovered', $testIndex), 4.0);
         $Probe->grouped = Client::send($Socket, Client::request('/deferred/recover/grouped', $testIndex), 4.0);
         $Probe->after = Client::send($Socket, Client::request('/deferred/recovered', $testIndex), 4.0);
         Client::close($Socket);
      }
      catch (Throwable $Throwable) {
         $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /deferred/ping HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: static function (Request $Request, Response $Response, Router $Router) use ($Outer, $Inner): Generator {
      // @ Counters, outside the group
      yield $Router->route('/deferred/recovered', static function (Request $Request, Response $Response) use ($Outer, $Inner) {
         $Response->JSON->send(['inner' => $Inner->recoveries, 'outer' => $Outer->recoveries]);

         return $Response;
      }, GET);
      // @ Group boundary + a nested route with its own boundary, and one without
      // ! Non-static: the Router binds group handlers to its Route
      yield $Router->route('/deferred/recover/:*', function () use ($Router, $Outer, $Inner): Generator {
         $Router->intercept($Outer);
         yield $Router->route('nested', static function (Request $Request, Response $Response) {
            return $Response->defer(static function (Response $Response): void {
               $Response->wait();
               throw new RuntimeException('deferred-throw');
            });
         }, GET, [$Inner]);
         yield $Router->route('grouped', static function (Request $Request, Response $Response) {
            return $Response->defer(static function (Response $Response): void {
               $Response->wait();
               throw new RuntimeException('deferred-throw');
            });
         }, GET);
      }, GET);
      yield from Routes::mount($Router);
   },

   test: new Assertions(Case: function (string $response) use ($Probe): Generator {
      $evidence = json_encode([
         'error' => $Probe->error,
         'before' => $Probe->before,
         'nested' => $Probe->nested,
         'middle' => $Probe->middle,
         'grouped' => $Probe->grouped,
         'after' => $Probe->after
      ]);
      $decode = static function (array $exchange): array {
         $decoded = json_decode((string) ($exchange['body'] ?? ''), true);
         return is_array($decoded) ? $decoded : [];
      };
      $before = $decode($Probe->before);
      $nested = $decode($Probe->nested);
      $middle = $decode($Probe->middle);
      $grouped = $decode($Probe->grouped);
      $after = $decode($Probe->after);

      yield new Assertion(
         description: 'The innermost boundary (route level) answers first',
         fallback: "Unexpected boundary for the nested route: {$evidence}"
      )
         ->expect([$Probe->nested['code'] ?? 0, $nested['recovered'] ?? null])
         ->to->be([500, 'inner'])
         ->assert();

      yield new Assertion(
         description: 'The first answer wins: the outer boundary is never consulted',
         fallback: "Unexpected recovery counters: {$evidence}"
      )
         ->expect([
            ($middle['inner'] ?? 0) - ($before['inner'] ?? 0),
            ($middle['outer'] ?? 0) - ($before['outer'] ?? 0)
         ])
         ->to->be([1, 0])
         ->assert();

      yield new Assertion(
         description: "The group's intercept() boundary answers a nested route without one of its own",
         fallback: "The merged chain was not carried: {$evidence}"
      )
         ->expect([
            $Probe->grouped['code'] ?? 0,
            $grouped['recovered'] ?? null,
            ($after['outer'] ?? 0) - ($middle['outer'] ?? 0)
         ])
         ->to->be([500, 'outer', 1])
         ->assert();
   })
);
