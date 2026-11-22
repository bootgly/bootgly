<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middleware;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Sealing;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Deferred\Deferrer;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Idle\Client;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * RTR-18: a route chain published while the Router resolved a request inside
 * a deferral stays that request's across a `wait()`: a nested `defer()` after
 * it clones the chain, so the settlement still runs the route's Sealing pass
 * (AccessLog, ETag and Compression ride the same pass) — and the handler read
 * its own params before the handoff.
 */
$PersistentRouter = new Router;
$Seal = new class implements Middleware, Sealing {
   public function process (object $Request, object $Response, Closure $Next): object
   {
      return $Next($Request, $Response);
   }

   public function seal (Request $Request, Response $Response): void
   {
      $Response->Header->set('X-R18-Sealed', 'yes');
   }
};
$Probe = new class {
   /** @var array<string,mixed> */
   public array $fast = [];
   /** @var array<string,mixed> */
   public array $nested = [];
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),
   middlewares: [new Deferrer('/r18/g/', $PersistentRouter)],

   request: static function (string $hostPort, int $testIndex) use ($Probe): string {
      try {
         $Socket = Client::open($hostPort);
         $Probe->fast = Client::send($Socket, Client::request('/r18/fast/6', $testIndex), 4.0);
         $Probe->nested = Client::send($Socket, Client::request('/r18/g/n/item/9', $testIndex), 4.0);
         Client::close($Socket);
      }
      catch (Throwable $Throwable) {
         $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /r18/ping HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: static function (Request $Request, Response $Response, Router $Router) use ($PersistentRouter, $Seal): Response {
      $PersistentRouter->route('/r18/fast/:id', function (Request $Request, Response $Response): Response {
         $id = $this->Params->id; // @phpstan-ignore variable.undefined

         return $Response(body: "id={$id}");
      }, GET);
      $PersistentRouter->route('/r18/g/n/item/:id', function (Request $Request, Response $Response): Response {
         $Response->wait();
         $id = $this->Params->id; // @phpstan-ignore variable.undefined

         return $Response->defer(static function (Response $Response) use ($id): void {
            $Response(body: "id={$id}");
         });
      }, GET, middlewares: [$Seal]);
      $PersistentRouter->route('/r18/ping', static function (Request $Request, Response $Response): Response {
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
      $evidence = json_encode(['error' => $Probe->error, 'fast' => $Probe->fast, 'nested' => $Probe->nested]);

      yield new Assertion(
         description: 'The nested deferral reads the params its request was routed with',
         fallback: "The handler read another request's params after the wait(): {$evidence}"
      )
         ->expect([$Probe->fast['body'] ?? null, $Probe->nested['code'] ?? 0, $Probe->nested['body'] ?? null])
         ->to->be(['id=6', 200, 'id=9'])
         ->assert();

      yield new Assertion(
         description: "The route's Sealing pass runs on the nested deferral's answer",
         fallback: "The nested deferral lost the route chain: {$evidence}"
      )
         ->expect(stripos((string) ($Probe->nested['head'] ?? ''), "\r\nX-R18-Sealed: yes") !== false)
         ->to->be(true)
         ->assert();
   })
);
