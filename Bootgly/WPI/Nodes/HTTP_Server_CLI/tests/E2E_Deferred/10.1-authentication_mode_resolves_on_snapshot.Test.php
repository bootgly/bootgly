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
use Bootgly\WPI\Events\Cancellation;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Bodies;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middleware;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authenticating;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication\Bearer;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Deferred\Routes;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Idle\Client;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * The deferring mode of `Authentication`: a Bearer guard whose lookup
 * suspends resolves inside the deferral while another request is answered;
 * the identity lands on the snapshot the handler receives, with its body
 * fields and headers; an unknown token gets the `invalid_token` 401 and a
 * handler's `forbid()` the 403 — both with `resource_metadata`; a throwing
 * guard is a 500. A request without credentials is answered the invitation
 * 401 synchronously: under a worker body budget that cannot hold its
 * snapshot it still gets the 401, while a credentialed one is refused (503).
 */
$metadata = 'https://rs.example/.well-known/oauth-protected-resource';
$Bearer = new Bearer(
   Resolver: static function (string $token, Request $Request): mixed {
      // @ The lookup suspends, as a database query does
      $WPI = WPI;
      Routes::park($WPI->Response, 0.3);

      if ($token === 'tok-throw') {
         throw new RuntimeException('guard failure');
      }

      return ['tok-alice' => 'alice'][$token] ?? null;
   },
   metadata: $metadata
);
$Mode = new Authentication(new Authenticating($Bearer), deferred: true, timeout: 3);
$Synchronous = new Authentication(new Authenticating($Bearer));
// ! Lowers the worker body budget for one request (`X-Budget`), so its
//   snapshot cannot be captured — restored once the onion returned
$Budget = new class implements Middleware {
   public function process (object $Request, object $Response, Closure $Next): object
   {
      if ($Request->Header->get('X-Budget') === null) {
         return $Next($Request, $Response);
      }

      $budget = Bodies::$maxWorkerBodySize;
      Bodies::$maxWorkerBodySize = 1024;
      try {
         return $Next($Request, $Response);
      }
      finally {
         Bodies::$maxWorkerBodySize = $budget;
      }
   }
};
$Probe = new class {
   /** @var array<string,array<string,mixed>> */
   public array $answers = [];
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),

   request: static function (string $hostPort, int $testIndex) use ($Probe): string {
      $post = static function (string $path, array $headers, string $body) use ($testIndex): string {
         $request = "POST {$path} HTTP/1.1\r\nHost: localhost\r\nX-Bootgly-Test: {$testIndex}\r\n"
            . "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($body) . "\r\n";
         foreach ($headers as $name => $value) {
            $request .= "{$name}: {$value}\r\n";
         }

         return "{$request}\r\n{$body}";
      };
      $big = 'alpha=1&pad=' . str_repeat('x', 4096);
      try {
         // @ A parked guard does not hold the worker: a request on another
         //   connection is answered while it waits
         $Parked = Client::open($hostPort);
         fwrite($Parked, $post('/am1/who', ['Authorization' => 'Bearer tok-alice', 'X-Echo' => 'echoed'], 'alpha=1'));
         $Other = Client::open($hostPort);
         $Probe->answers['ping'] = Client::send($Other, Client::request('/am1/ping', $testIndex), 4.0);
         Client::close($Other);
         $Probe->answers['who'] = Client::send($Parked, '', 4.0);
         Client::close($Parked);

         $requests = [
            'bad' => Client::request('/am1/who', $testIndex, ['Authorization' => 'Bearer tok-nobody']),
            'forbid' => Client::request('/am1/forbid', $testIndex, ['Authorization' => 'Bearer tok-alice']),
            'throw' => Client::request('/am1/who', $testIndex, ['Authorization' => 'Bearer tok-throw']),
            'none' => $post('/am1/who', ['X-Budget' => '1'], $big),
            'none-sync' => $post('/am1/sync', [], $big),
            'charged' => $post('/am1/who', ['X-Budget' => '1', 'Authorization' => 'Bearer tok-alice'], $big)
         ];
         foreach ($requests as $key => $request) {
            $Socket = Client::open($hostPort);
            $Probe->answers[$key] = Client::send($Socket, $request, 4.0);
            Client::close($Socket);
         }
      }
      catch (Throwable $Throwable) {
         $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /deferred/ping HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: static function (Request $Request, Response $Response, Router $Router) use ($Mode, $Synchronous, $Budget, $Bearer): Generator {
      yield $Router->route('/am1/ping', static function (Request $Request, Response $Response) {
         return $Response(body: 'pong');
      }, GET);
      yield $Router->route('/am1/who', static function (Request $Request, Response $Response) {
         $WPI = WPI;
         $Generation = Cancellation::fetch($Request);
         $Response->JSON->send([
            'identity' => $Request->identity,
            'field' => $Request->fields['alpha'] ?? null,
            'header' => $Request->Header->get('X-Echo'),
            'ambient' => $WPI->Response->Request === $Request,
            'snapshot' => $Generation !== null && $Generation->check() === false
         ]);

         return $Response;
      }, [GET, POST], middlewares: [$Budget, $Mode]);
      yield $Router->route('/am1/sync', static function (Request $Request, Response $Response) {
         return $Response(body: 'sync');
      }, POST, middlewares: [$Synchronous]);
      yield $Router->route('/am1/forbid', static function (Request $Request, Response $Response) use ($Bearer) {
         return $Bearer->forbid($Response, ['admin']);
      }, GET, middlewares: [$Mode]);
      yield from Routes::mount($Router);
   },

   test: new Assertions(Case: function (string $response) use ($Probe, $metadata): Generator {
      $A = $Probe->answers;
      $evidence = json_encode(['error' => $Probe->error, 'answers' => $A]);
      $who = json_decode((string) ($A['who']['body'] ?? ''), true);
      $who = is_array($who) ? $who : [];
      $challenge = static function (array $answer): string {
         $matches = [];
         preg_match('/\r\nWWW-Authenticate: ([^\r\n]*)/i', (string) ($answer['head'] ?? ''), $matches);

         return $matches[1] ?? '';
      };

      yield new Assertion(
         description: 'A suspending guard resolves inside the deferral; the identity, fields and headers ride the snapshot',
         fallback: "The deferred authentication did not answer on its snapshot: {$evidence}"
      )
         ->expect([$A['who']['code'] ?? 0, $who])
         ->to->be([200, ['identity' => 'alice', 'field' => '1', 'header' => 'echoed', 'ambient' => true, 'snapshot' => true]])
         ->assert();

      yield new Assertion(
         description: 'Another request is answered while the guard is parked',
         fallback: "The parked guard held the worker: {$evidence}"
      )
         ->expect([$A['ping']['code'] ?? 0, $A['ping']['body'] ?? null, ($A['ping']['elapsed'] ?? 9.0) < 0.25])
         ->to->be([200, 'pong', true])
         ->assert();

      yield new Assertion(
         description: 'An unknown token gets the invalid_token 401 from inside the deferral',
         fallback: "Unexpected refusal: {$evidence}"
      )
         ->expect([$A['bad']['code'] ?? 0, $challenge($A['bad'] ?? [])])
         ->to->be([401, "Bearer realm=\"Protected area\", error=\"invalid_token\", resource_metadata=\"{$metadata}\""])
         ->assert();

      yield new Assertion(
         description: 'A handler behind the mode answers forbid() with the 403 step-up challenge',
         fallback: "Unexpected forbid: {$evidence}"
      )
         ->expect([$A['forbid']['code'] ?? 0, $challenge($A['forbid'] ?? [])])
         ->to->be([403, "Bearer realm=\"Protected area\", error=\"insufficient_scope\", scope=\"admin\", resource_metadata=\"{$metadata}\""])
         ->assert();

      yield new Assertion(
         description: 'A throwing guard is answered 500',
         fallback: "Unexpected answer to a throwing guard: {$evidence}"
      )
         ->expect($A['throw']['code'] ?? 0)
         ->to->be(500)
         ->assert();

      yield new Assertion(
         description: 'Without credentials the invitation 401 is answered synchronously: no snapshot is captured',
         fallback: "The credential-less request was deferred (its snapshot charged): {$evidence}"
      )
         ->expect([$A['none']['code'] ?? 0, $challenge($A['none'] ?? []), $challenge($A['none-sync'] ?? [])])
         ->to->be([401, "Bearer realm=\"Protected area\", resource_metadata=\"{$metadata}\"", "Bearer realm=\"Protected area\", resource_metadata=\"{$metadata}\""])
         ->assert();

      yield new Assertion(
         description: 'A credentialed request under the same budget defers and is refused (503)',
         fallback: "The budget did not bind the deferral: {$evidence}"
      )
         ->expect($A['charged']['code'] ?? 0)
         ->to->be(503)
         ->assert();
   })
);
