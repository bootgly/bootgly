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
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Deferred\Deferrer;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Idle\Client;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * RTR-18: once the Router resolved a request inside a deferral (a global
 * middleware deferred ahead of routing), the path and params it wrote stay
 * that request's across a `wait()` — for the handler's Route and for the
 * ambient `WPI->Router->Route` alike — instead of reverting to whatever the
 * Route held when the deferral began (the previous request's).
 */
$PersistentRouter = new Router;
$Probe = new class {
   /** @var array<string,mixed> */
   public array $fast = [];
   /** @var array<string,mixed> */
   public array $waited = [];
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),
   middlewares: [new Deferrer('/r18/g/', $PersistentRouter)],

   request: static function (string $hostPort, int $testIndex) use ($Probe): string {
      try {
         $Socket = Client::open($hostPort);
         // @ A synchronous dynamic request leaves its path and params on the Route
         $Probe->fast = Client::send($Socket, Client::request('/r18/fast/5', $testIndex), 4.0);
         $Probe->waited = Client::send($Socket, Client::request('/r18/g/w/item/7', $testIndex), 4.0);
         Client::close($Socket);
      }
      catch (Throwable $Throwable) {
         $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /r18/ping HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: static function (Request $Request, Response $Response, Router $Router) use ($PersistentRouter): Response {
      $PersistentRouter->route('/r18/fast/:id', function (Request $Request, Response $Response): Response {
         $id = $this->Params->id; // @phpstan-ignore variable.undefined
         $path = $this->path; // @phpstan-ignore variable.undefined

         return $Response(body: "id={$id};path={$path}");
      }, GET);
      $PersistentRouter->route('/r18/g/w/item/:id', function (Request $Request, Response $Response): Response {
         $path = $this->path; // @phpstan-ignore variable.undefined
         $id = $this->Params->id; // @phpstan-ignore variable.undefined
         $before = "{$path}#{$id}";
         $Response->wait();
         $path = $this->path; // @phpstan-ignore variable.undefined
         $id = $this->Params->id; // @phpstan-ignore variable.undefined
         $after = "{$path}#{$id}";
         $WPI = WPI;
         $Ambient = $WPI->Router->Route;
         $ambient = "{$Ambient->path}#{$Ambient->Params->id}";

         return $Response(body: "before={$before};after={$after};ambient={$ambient}");
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
      $evidence = json_encode(['error' => $Probe->error, 'fast' => $Probe->fast, 'waited' => $Probe->waited]);

      yield new Assertion(
         description: 'The synchronous dynamic request reads its own Route state',
         fallback: "Unexpected synchronous answer: {$evidence}"
      )
         ->expect($Probe->fast['body'] ?? null)
         ->to->be('id=5;path=/r18/fast/5')
         ->assert();

      yield new Assertion(
         description: 'Path and params routed inside the deferral survive a wait()',
         fallback: "The Route state reverted across the wait(): {$evidence}"
      )
         ->expect([$Probe->waited['code'] ?? 0, $Probe->waited['body'] ?? null])
         ->to->be([200, 'before=/r18/g/w/item/7#7;after=/r18/g/w/item/7#7;ambient=/r18/g/w/item/7#7'])
         ->assert();
   })
);
