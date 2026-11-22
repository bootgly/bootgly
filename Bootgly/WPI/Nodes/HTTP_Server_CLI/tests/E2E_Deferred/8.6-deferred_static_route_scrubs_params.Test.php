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
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Deferred\Deferrer;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Idle\Client;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * RTR-18: static routes read no params. A dynamic request leaves its params
 * on the Router's Route; the next resolve scrubs them before a static
 * dispatch. A static route resolved inside a deferral scrubs the deferred
 * generation's copy — and the Route's own params must still be scrubbed for
 * the next synchronous static request, not skipped as already clean.
 */
$PersistentRouter = new Router;
$Probe = new class {
   /** @var array<string,mixed> */
   public array $fast = [];
   /** @var array<string,mixed> */
   public array $deferred = [];
   /** @var array<string,mixed> */
   public array $static = [];
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),
   middlewares: [new Deferrer('/r18/g/', $PersistentRouter)],

   request: static function (string $hostPort, int $testIndex) use ($Probe): string {
      try {
         $Socket = Client::open($hostPort);
         $Probe->fast = Client::send($Socket, Client::request('/r18/fast/5', $testIndex), 4.0);
         $Probe->deferred = Client::send($Socket, Client::request('/r18/g/static', $testIndex), 4.0);
         $Probe->static = Client::send($Socket, Client::request('/r18/static', $testIndex), 4.0);
         Client::close($Socket);
      }
      catch (Throwable $Throwable) {
         $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /r18/ping HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: static function (Request $Request, Response $Response, Router $Router) use ($PersistentRouter): Response {
      $Handler = function (Request $Request, Response $Response): Response {
         $id = $this->Params->id; // @phpstan-ignore variable.undefined
         $id = var_export($id, true);

         return $Response(body: "id={$id}");
      };
      $PersistentRouter->route('/r18/fast/:id', $Handler, GET);
      $PersistentRouter->route('/r18/g/static', $Handler, GET);
      $PersistentRouter->route('/r18/static', $Handler, GET);
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
         'fast' => $Probe->fast,
         'deferred' => $Probe->deferred,
         'static' => $Probe->static
      ]);

      yield new Assertion(
         description: 'The dynamic request reads its param',
         fallback: "Unexpected dynamic answer: {$evidence}"
      )
         ->expect($Probe->fast['body'] ?? null)
         ->to->be("id='5'")
         ->assert();

      yield new Assertion(
         description: 'A static route resolved inside a deferral reads no params',
         fallback: "The deferred static route read a previous request's param: {$evidence}"
      )
         ->expect($Probe->deferred['body'] ?? null)
         ->to->be('id=NULL')
         ->assert();

      yield new Assertion(
         description: 'The next synchronous static route reads no params either',
         fallback: "The synchronous static route read a previous request's param: {$evidence}"
      )
         ->expect($Probe->static['body'] ?? null)
         ->to->be('id=NULL')
         ->assert();
   })
);
