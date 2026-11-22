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
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Recovering;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Deferred\Deferrer;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Deferred\Sealer;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Idle\Client;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * RTR-18: a handler routed inside a deferral that suspends with `wait()`
 * settles through the same chain as one that does not. The route chain the
 * Router published for it runs its Sealing pass exactly once (the onion's,
 * not again at settlement), and a throw after the `wait()` reaches the same
 * boundary as a throw without it.
 */
$PersistentRouter = new Router;
$Sealer = new Sealer('r18');
$Boundary = new class implements Middleware, Recovering {
   public function process (object $Request, object $Response, Closure $Next): object
   {
      return $Next($Request, $Response);
   }

   public function recover (Request $Request, Response $Response, Throwable $Throwable): null|Response
   {
      return $Response(code: 418, body: 'route-boundary');
   }
};
$Probe = new class {
   /** @var array<string,array<string,mixed>> */
   public array $answers = [];
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),
   middlewares: [new Deferrer('/r18/s/', $PersistentRouter)],

   request: static function (string $hostPort, int $testIndex) use ($Probe): string {
      $requests = [
         'now' => ['/r18/s/now/1', []],
         'wait' => ['/r18/s/wait/2', []],
         'count' => ['/r18/count', []],
         'throw' => ['/r18/s/throw/3', []],
         'throw-wait' => ['/r18/s/throw/4', ['X-R18-Wait' => '1']]
      ];
      try {
         foreach ($requests as $key => [$path, $headers]) {
            $Socket = Client::open($hostPort);
            $Probe->answers[$key] = Client::send($Socket, Client::request($path, $testIndex, $headers), 4.0);
            Client::close($Socket);
         }
      }
      catch (Throwable $Throwable) {
         $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /r18/ping HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: static function (Request $Request, Response $Response, Router $Router) use ($PersistentRouter, $Sealer, $Boundary): Response {
      $PersistentRouter->route('/r18/s/now/:id', function (Request $Request, Response $Response): Response {
         $id = $this->Params->id; // @phpstan-ignore variable.undefined

         return $Response(body: "id={$id}");
      }, GET, middlewares: [$Sealer]);
      $PersistentRouter->route('/r18/s/wait/:id', function (Request $Request, Response $Response): Response {
         $Response->wait();
         $id = $this->Params->id; // @phpstan-ignore variable.undefined

         return $Response(body: "id={$id}");
      }, GET, middlewares: [$Sealer]);
      $PersistentRouter->route('/r18/s/throw/:id', static function (Request $Request, Response $Response): Response {
         if ($Request->Header->get('X-R18-Wait') !== null) {
            $Response->wait();
         }

         throw new RuntimeException('r18-throw');
      }, GET, middlewares: [$Boundary]);
      $PersistentRouter->route('/r18/count', static function (Request $Request, Response $Response) use ($Sealer): Response {
         return $Response(body: "seals={$Sealer->seals}");
      }, GET);
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
      $answers = $Probe->answers;
      $evidence = json_encode(['error' => $Probe->error, 'answers' => $answers]);

      yield new Assertion(
         description: 'Both deferred routes answer their own params, sealed by the route chain',
         fallback: "A deferred route lost its params or its seal: {$evidence}"
      )
         ->expect([
            $answers['now']['body'] ?? null,
            stripos((string) ($answers['now']['head'] ?? ''), "\r\nX-Sealed-r18: 200") !== false,
            $answers['wait']['body'] ?? null,
            stripos((string) ($answers['wait']['head'] ?? ''), "\r\nX-Sealed-r18: 200") !== false
         ])
         ->to->be(['id=1', true, 'id=2', true])
         ->assert();

      yield new Assertion(
         description: 'The route Sealing pass runs once per request, wait() or not',
         fallback: "The settlement ran the route chain's seal again: {$evidence}"
      )
         ->expect($answers['count']['body'] ?? null)
         ->to->be('seals=2')
         ->assert();

      yield new Assertion(
         description: 'A throw after wait() reaches the same boundary as one without it',
         fallback: "The wait() changed which boundary answered the throw: {$evidence}"
      )
         ->expect([$answers['throw']['code'] ?? 0, $answers['throw-wait']['code'] ?? 0])
         ->to->be([500, 500])
         ->assert();
   })
);
