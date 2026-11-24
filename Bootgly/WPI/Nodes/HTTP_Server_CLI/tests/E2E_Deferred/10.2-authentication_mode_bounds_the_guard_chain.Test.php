<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use const Bootgly\WPI;

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authenticating;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication\Bearer;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Deferred\Routes;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Idle\Client;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * The deferring mode bounds its guard chain, never the handler: a guard
 * parked past `timeout` is answered 503 at about the bound — also when the
 * guard swallows the Timeout or converts it into another Throwable; a guard
 * that catches the Timeout and waits again is interrupted again, a bound
 * later, never after its wait. `timeout: 0` leaves the chain unbounded. An
 * authorized handler that parks longer than the bound, or defers again, is
 * answered in full, and a later deferral on a reused Fiber is never cut by
 * a stale guard timer. A Response other than the deferred one — returned by
 * the handler or by a Fallback — is refused: 401 with the challenge, none of
 * its headers or body.
 */
$Bearer = new Bearer(
   Resolver: static function (string $token, Request $Request): mixed {
      $WPI = WPI;
      switch ($token) {
         case 'tok-slow':
            Routes::park($WPI->Response, 2.0);
            return 'late';
         case 'tok-swallow':
            try {
               Routes::park($WPI->Response, 2.0);
            }
            catch (Throwable) {
               // A guard that swallows whatever interrupted it
            }
            return null;
         case 'tok-convert':
            try {
               Routes::park($WPI->Response, 2.0);
            }
            catch (Throwable $Throwable) {
               throw new RuntimeException('lookup failed', 0, $Throwable);
            }
         case 'tok-retry':
            try {
               Routes::park($WPI->Response, 2.0);
            }
            catch (Throwable) {
               // A guard that retries its lookup after the interruption
               Routes::park($WPI->Response, 2.0);
            }
            return 'late';
         case 'tok-late':
            Routes::park($WPI->Response, 0.8);
            return 'late';
         case 'tok-quick':
            Routes::park($WPI->Response, 0.05);
            return 'alice';
      }

      return null;
   }
);
$Mode = new Authentication(new Authenticating($Bearer), deferred: true, timeout: 0.5);
$Unbounded = new Authentication(new Authenticating($Bearer), deferred: true, timeout: 0);
$Fallen = new Authentication(
   new Authenticating($Bearer),
   Fallback: static function (object $Request, object $Response): object {
      $Foreign = new Response;
      $Foreign->Header->queue('X-Leak', 'fallback');

      return $Foreign(code: 401, body: 'fallback leak');
   },
   deferred: true,
   timeout: 0.5
);
$Probe = new class {
   /** @var array<string,array<string,mixed>> */
   public array $answers = [];
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),

   request: static function (string $hostPort, int $testIndex) use ($Probe): string {
      $requests = [
         'slow' => ['/am2/who', 'tok-slow'],
         'swallow' => ['/am2/who', 'tok-swallow'],
         'convert' => ['/am2/who', 'tok-convert'],
         'retry' => ['/am2/who', 'tok-retry'],
         'unbounded' => ['/am2/unbounded', 'tok-late'],
         'quick' => ['/am2/who', 'tok-quick'],
         'plain' => ['/am2/plain', ''],
         'handler' => ['/am2/handler', 'tok-quick'],
         'nested' => ['/am2/nested', 'tok-quick'],
         'foreign' => ['/am2/foreign', 'tok-quick'],
         'fallback' => ['/am2/fallback', 'tok-nobody']
      ];
      try {
         foreach ($requests as $key => [$path, $token]) {
            $headers = $token === '' ? [] : ['Authorization' => "Bearer {$token}"];
            $Socket = Client::open($hostPort);
            $Probe->answers[$key] = Client::send($Socket, Client::request($path, $testIndex, $headers), 4.0);
            Client::close($Socket);
         }
      }
      catch (Throwable $Throwable) {
         $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /deferred/ping HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: static function (Request $Request, Response $Response, Router $Router) use ($Mode, $Unbounded, $Fallen): Generator {
      yield $Router->route('/am2/who', static function (Request $Request, Response $Response) {
         return $Response(body: (string) $Request->identity);
      }, GET, middlewares: [$Mode]);
      yield $Router->route('/am2/unbounded', static function (Request $Request, Response $Response) {
         return $Response(body: (string) $Request->identity);
      }, GET, middlewares: [$Unbounded]);
      // ! A plain deferral that parks past the guard bound — run right after
      //   a bounded chain settled, on the pooled Fiber it released
      yield $Router->route('/am2/plain', static function (Request $Request, Response $Response) {
         return $Response->defer(static function (Response $Response): void {
            Routes::park($Response, 1.0);
            $Response(body: 'plain');
         });
      }, GET);
      yield $Router->route('/am2/handler', static function (Request $Request, Response $Response) {
         Routes::park($Response, 1.0);

         return $Response(body: "handler {$Request->identity}");
      }, GET, middlewares: [$Mode]);
      yield $Router->route('/am2/nested', static function (Request $Request, Response $Response) {
         return $Response->defer(static function (Response $Response, Request $Request): void {
            Routes::park($Response, 0.8);
            $Response(body: "nested {$Request->identity}");
         });
      }, GET, middlewares: [$Mode]);
      yield $Router->route('/am2/foreign', static function (Request $Request, Response $Response) {
         $Foreign = new Response;
         $Foreign->Header->queue('X-Leak', 'handler');

         return $Foreign(code: 200, body: 'handler leak');
      }, GET, middlewares: [$Mode]);
      yield $Router->route('/am2/fallback', static function (Request $Request, Response $Response) {
         return $Response(body: 'unreachable');
      }, GET, middlewares: [$Fallen]);
      yield from Routes::mount($Router);
   },

   test: new Assertions(Case: function (string $response) use ($Probe): Generator {
      $A = $Probe->answers;
      $evidence = json_encode(['error' => $Probe->error, 'answers' => $A]);
      $within = static function (array $answer, float $low, float $high): bool {
         $elapsed = $answer['elapsed'] ?? 9.0;

         return $elapsed >= $low && $elapsed < $high;
      };

      yield new Assertion(
         description: 'A guard parked past the bound is answered 503 at about the bound',
         fallback: "The guard chain was not bounded: {$evidence}"
      )
         ->expect([$A['slow']['code'] ?? 0, $within($A['slow'] ?? [], 0.45, 1.5)])
         ->to->be([503, true])
         ->assert();

      yield new Assertion(
         description: 'A guard that swallows the Timeout is still answered 503 — never the 401 of its null',
         fallback: "A swallowed bound reached the denial: {$evidence}"
      )
         ->expect([$A['swallow']['code'] ?? 0, $within($A['swallow'] ?? [], 0.45, 1.5)])
         ->to->be([503, true])
         ->assert();

      yield new Assertion(
         description: 'A guard that converts the Timeout into another Throwable is answered 503 — never 500',
         fallback: "A converted bound reached the Catcher as an error: {$evidence}"
      )
         ->expect([$A['convert']['code'] ?? 0, $within($A['convert'] ?? [], 0.45, 1.5)])
         ->to->be([503, true])
         ->assert();

      yield new Assertion(
         description: 'A guard that waits again after the Timeout is interrupted again, a bound later — never after its wait',
         fallback: "A retrying guard outlived the bound: {$evidence}"
      )
         ->expect([$A['retry']['code'] ?? 0, $within($A['retry'] ?? [], 0.9, 1.8)])
         ->to->be([503, true])
         ->assert();

      yield new Assertion(
         description: 'timeout: 0 leaves the guard chain unbounded: a guard parked past 0.5 s authenticates',
         fallback: "An unbounded chain was cut: {$evidence}"
      )
         ->expect([$A['unbounded']['code'] ?? 0, $A['unbounded']['body'] ?? null, $within($A['unbounded'] ?? [], 0.75, 3.0)])
         ->to->be([200, 'late', true])
         ->assert();

      yield new Assertion(
         description: 'A guard within the bound authenticates',
         fallback: "A quick guard was refused: {$evidence}"
      )
         ->expect([$A['quick']['code'] ?? 0, $A['quick']['body'] ?? null])
         ->to->be([200, 'alice'])
         ->assert();

      yield new Assertion(
         description: 'A later deferral on the released Fiber is never cut by a stale guard timer',
         fallback: "A stale guard timer interrupted another deferral: {$evidence}"
      )
         ->expect([$A['plain']['code'] ?? 0, $A['plain']['body'] ?? null])
         ->to->be([200, 'plain'])
         ->assert();

      yield new Assertion(
         description: 'The handler is not bounded by the guard timeout: it parks past it and answers',
         fallback: "The guard bound cut the handler: {$evidence}"
      )
         ->expect([$A['handler']['code'] ?? 0, $A['handler']['body'] ?? null, $within($A['handler'] ?? [], 1.0, 3.0)])
         ->to->be([200, 'handler alice', true])
         ->assert();

      yield new Assertion(
         description: 'A handler that defers again is answered by its nested deferral',
         fallback: "The nested deferral did not answer: {$evidence}"
      )
         ->expect([$A['nested']['code'] ?? 0, $A['nested']['body'] ?? null])
         ->to->be([200, 'nested alice'])
         ->assert();

      yield new Assertion(
         description: 'A foreign Response from the handler is refused: 401, none of its headers or body',
         fallback: "A foreign handler Response leaked: {$evidence}"
      )
         ->expect([
            $A['foreign']['code'] ?? 0,
            stripos((string) ($A['foreign']['head'] ?? ''), 'X-Leak') === false,
            str_contains((string) ($A['foreign']['body'] ?? 'leak'), 'leak'),
            stripos((string) ($A['foreign']['head'] ?? ''), "\r\nWWW-Authenticate: Bearer") !== false
         ])
         ->to->be([401, true, false, true])
         ->assert();

      yield new Assertion(
         description: 'A foreign Response from a Fallback is refused: 401, none of its headers or body',
         fallback: "A foreign Fallback Response leaked: {$evidence}"
      )
         ->expect([
            $A['fallback']['code'] ?? 0,
            stripos((string) ($A['fallback']['head'] ?? ''), 'X-Leak') === false,
            str_contains((string) ($A['fallback']['body'] ?? 'leak'), 'leak')
         ])
         ->to->be([401, true, false])
         ->assert();
   })
);
