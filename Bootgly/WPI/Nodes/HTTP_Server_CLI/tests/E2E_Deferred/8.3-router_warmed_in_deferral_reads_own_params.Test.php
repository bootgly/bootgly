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
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middleware;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Deferred\Deferrer;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Idle\Client;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * RTR-18: routes register lazily, on the first request. When a global
 * middleware defers that first request, the Router registers — and binds
 * every handler — inside the deferral. Each later request must still read
 * its own params: the handlers stay bound to the Router's one Route, never
 * to the first request's deferred copy of it.
 */
$PersistentRouter = new Router;
$Probe = new class {
   /** @var array<string,mixed> */
   public array $first = [];
   /** @var array<string,mixed> */
   public array $second = [];
   /** @var array<string,mixed> */
   public array $guarded = [];
   public string $error = '';
};
$Pass = new class implements Middleware {
   public function process (object $Request, object $Response, Closure $Next): object
   {
      return $Next($Request, $Response);
   }
};

return new Test(
   Separator: new Separator(line: ''),
   middlewares: [new Deferrer('/r18/g/', $PersistentRouter)],

   request: static function (string $hostPort, int $testIndex) use ($Probe): string {
      try {
         $Socket = Client::open($hostPort);
         // @ The first request the Router sees is deferred: it warms inside the deferral
         $Probe->first = Client::send($Socket, Client::request('/r18/g/item/99', $testIndex), 4.0);
         $Probe->second = Client::send($Socket, Client::request('/r18/g/item/7', $testIndex), 4.0);
         $Probe->guarded = Client::send($Socket, Client::request('/r18/g/guarded/item/8', $testIndex), 4.0);
         Client::close($Socket);
      }
      catch (Throwable $Throwable) {
         $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /r18/ping HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: static function (Request $Request, Response $Response, Router $Router) use ($PersistentRouter, $Pass): Response {
      $Handler = function (Request $Request, Response $Response): Response {
         $WPI = WPI;
         $id = $this->Params->id; // @phpstan-ignore variable.undefined
         $path = $this->path; // @phpstan-ignore variable.undefined

         return $Response(body: "id={$id};path={$path};ambient={$WPI->Request->URI}");
      };
      $PersistentRouter->route('/r18/g/item/:id', $Handler, GET);
      $PersistentRouter->route('/r18/g/guarded/item/:id', $Handler, GET, middlewares: [$Pass]);
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
      $evidence = json_encode([
         'error' => $Probe->error,
         'first' => $Probe->first,
         'second' => $Probe->second,
         'guarded' => $Probe->guarded
      ]);

      yield new Assertion(
         description: 'The request that warmed the Router inside its deferral reads its own params',
         fallback: "Unexpected first answer: {$evidence}"
      )
         ->expect([$Probe->first['code'] ?? 0, $Probe->first['body'] ?? null])
         ->to->be([200, 'id=99;path=/r18/g/item/99;ambient=/r18/g/item/99'])
         ->assert();

      yield new Assertion(
         description: 'A later request on the same route reads its own params, not the first one\'s',
         fallback: "The handler stayed bound to the first request's Route state: {$evidence}"
      )
         ->expect([$Probe->second['code'] ?? 0, $Probe->second['body'] ?? null])
         ->to->be([200, 'id=7;path=/r18/g/item/7;ambient=/r18/g/item/7'])
         ->assert();

      yield new Assertion(
         description: 'So does a later request on a route with its own middleware',
         fallback: "The guarded handler stayed bound to the first request's Route state: {$evidence}"
      )
         ->expect([$Probe->guarded['code'] ?? 0, $Probe->guarded['body'] ?? null])
         ->to->be([200, 'id=8;path=/r18/g/guarded/item/8;ambient=/r18/g/guarded/item/8'])
         ->assert();
   })
);
