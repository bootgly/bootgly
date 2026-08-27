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
 * BG-14: a `recover()` that throws hands its NEW Throwable to the next
 * boundary outward — nested-catch semantics — and a throwing boundary at the
 * end of the walk still lands on the Catcher, with the Fiber intact.
 */
$Outer = new Boundary('outer');
$Inner = new Boundary('inner', mode: 'throw');
$Solo = new Boundary('solo', mode: 'throw');
$Probe = new class {
   /** @var array<string,mixed> */
   public array $before = [];
   /** @var array<string,mixed> */
   public array $rethrow = [];
   /** @var array<string,mixed> */
   public array $middle = [];
   /** @var array<string,mixed> */
   public array $all = [];
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
         $Probe->rethrow = Client::send($Socket, Client::request('/deferred/recover/rethrow', $testIndex), 4.0);
         $Probe->middle = Client::send($Socket, Client::request('/deferred/recovered', $testIndex), 4.0);
         // ! Last on the connection: the Catcher's answer may close it
         $Probe->all = Client::send($Socket, Client::request('/deferred/recover/rethrow/all', $testIndex), 4.0);
         Client::close($Socket);

         $Socket = Client::open($hostPort);
         $Probe->after = Client::send($Socket, Client::request('/deferred/recovered', $testIndex), 4.0);
         Client::close($Socket);
      }
      catch (Throwable $Throwable) {
         $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /deferred/ping HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: static function (Request $Request, Response $Response, Router $Router) use ($Outer, $Inner, $Solo): Generator {
      yield $Router->route('/deferred/recovered', static function (Request $Request, Response $Response) use ($Outer, $Inner, $Solo) {
         $Response->JSON->send([
            'inner' => $Inner->recoveries,
            'outer' => $Outer->recoveries,
            'solo' => $Solo->recoveries
         ]);

         return $Response;
      }, GET);
      // @ Outer first in the list = outer in the onion; the inner one throws
      yield $Router->route('/deferred/recover/rethrow', static function (Request $Request, Response $Response) {
         return $Response->defer(static function (Response $Response): void {
            $Response->wait();
            throw new RuntimeException('deferred-throw');
         });
      }, GET, [$Outer, $Inner]);
      // @ A lone throwing boundary: nothing outward but the Catcher
      yield $Router->route('/deferred/recover/rethrow/all', static function (Request $Request, Response $Response) {
         return $Response->defer(static function (Response $Response): void {
            $Response->wait();
            throw new RuntimeException('deferred-throw');
         });
      }, GET, [$Solo]);
      yield from Routes::mount($Router);
   },

   test: new Assertions(Case: function (string $response) use ($Probe): Generator {
      $evidence = json_encode([
         'error' => $Probe->error,
         'before' => $Probe->before,
         'rethrow' => $Probe->rethrow,
         'middle' => $Probe->middle,
         'all' => $Probe->all,
         'after' => $Probe->after
      ]);
      $decode = static function (array $exchange): array {
         $decoded = json_decode((string) ($exchange['body'] ?? ''), true);
         return is_array($decoded) ? $decoded : [];
      };
      $before = $decode($Probe->before);
      $rethrow = $decode($Probe->rethrow);
      $middle = $decode($Probe->middle);
      $after = $decode($Probe->after);

      yield new Assertion(
         description: 'The outer boundary answers when the inner one throws',
         fallback: "Unexpected boundary after the inner throw: {$evidence}"
      )
         ->expect([$Probe->rethrow['code'] ?? 0, $rethrow['recovered'] ?? null])
         ->to->be([500, 'outer'])
         ->assert();

      yield new Assertion(
         description: 'The outer boundary receives the NEW Throwable, not the original one',
         fallback: "The replaced Throwable did not travel outward: {$evidence}"
      )
         ->expect([$rethrow['throwable'] ?? null, $rethrow['message'] ?? null])
         ->to->be([LogicException::class, 'inner-failed'])
         ->assert();

      yield new Assertion(
         description: 'Both boundaries were consulted exactly once',
         fallback: "Unexpected recovery counters: {$evidence}"
      )
         ->expect([
            ($middle['inner'] ?? 0) - ($before['inner'] ?? 0),
            ($middle['outer'] ?? 0) - ($before['outer'] ?? 0)
         ])
         ->to->be([1, 1])
         ->assert();

      yield new Assertion(
         description: 'A throwing boundary at the end of the walk lands on the Catcher',
         fallback: "The walk did not end on the Catcher: {$evidence}"
      )
         ->expect([
            $Probe->all['code'] ?? 0,
            $Probe->all['body'] ?? null,
            ($after['solo'] ?? 0) - ($middle['solo'] ?? 0)
         ])
         ->to->be([500, ' ', 1])
         ->assert();
   })
);
