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
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Idle\Client;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * RTR-18: work deferred from deferred work is bound to the NEW generation's
 * Route — the one the ambient `WPI->Router->Route` serves while it runs — not
 * to a detached copy of the parent generation's: a param it writes through
 * `$this` is the one the ambient Route reads.
 */
$PersistentRouter = new Router;
$Probe = new class {
   /** @var array<string,mixed> */
   public array $fast = [];
   /** @var array<string,mixed> */
   public array $nested = [];
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),

   request: static function (string $hostPort, int $testIndex) use ($Probe): string {
      try {
         $Socket = Client::open($hostPort);
         $Probe->fast = Client::send($Socket, Client::request('/r18/fast/1', $testIndex), 4.0);
         $Probe->nested = Client::send($Socket, Client::request('/r18/nn/item/4', $testIndex), 4.0);
         Client::close($Socket);
      }
      catch (Throwable $Throwable) {
         $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /r18/ping HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: static function (Request $Request, Response $Response, Router $Router) use ($PersistentRouter): Response {
      // ! One Route across requests, as the production Encoder_ path has
      $WPI = WPI;
      $WPI->Router = $PersistentRouter;

      $PersistentRouter->route('/r18/fast/:id', function (Request $Request, Response $Response): Response {
         $id = $this->Params->id; // @phpstan-ignore variable.undefined

         return $Response(body: "id={$id}");
      }, GET);
      $PersistentRouter->route('/r18/nn/item/:id', function (Request $Request, Response $Response): Response {
         return $Response->defer(function (Response $Response): void {
            $Response->defer(function (Response $Response): void {
               $this->Params->mark = 'w2'; // @phpstan-ignore variable.undefined
               $id = $this->Params->id; // @phpstan-ignore variable.undefined
               $WPI = WPI;
               $mark = var_export($WPI->Router->Route->Params->mark, true);

               $Response(body: "id={$id};ambient.mark={$mark}");
            });
         });
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
      $evidence = json_encode(['error' => $Probe->error, 'fast' => $Probe->fast, 'nested' => $Probe->nested]);

      yield new Assertion(
         description: 'Nested deferred work reads its request\'s params and writes the Route the ambient Router serves',
         fallback: "The nested work was bound to a detached Route: {$evidence}"
      )
         ->expect([$Probe->fast['body'] ?? null, $Probe->nested['code'] ?? 0, $Probe->nested['body'] ?? null])
         ->to->be(['id=1', 200, "id=4;ambient.mark='w2'"])
         ->assert();
   })
);
