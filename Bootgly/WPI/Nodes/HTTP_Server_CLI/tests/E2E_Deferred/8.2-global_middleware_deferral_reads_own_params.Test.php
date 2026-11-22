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
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Deferred\Barrier;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Deferred\Deferrer;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Idle\Client;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * RTR-18: a middleware-free route reached through a GLOBAL middleware that
 * defers ahead of routing — the Router resolves it inside the deferral —
 * reads its request's params, not those of a request the Router resolved
 * synchronously (same parameter name, another value) while it was parked.
 *
 * The Router warms synchronously first, so the handler is bound to its one
 * Route; routing inside the deferral writes through that Route.
 */
$PersistentRouter = new Router;
$Barrier = new Barrier;
$Probe = new class {
   /** @var array<string,mixed> */
   public array $warm = [];
   public bool $held = false;
   /** @var array<string,mixed> */
   public array $parked = [];
   /** @var array<string,mixed> */
   public array $routed = [];
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),
   middlewares: [new Deferrer('/r18/g/', $PersistentRouter, $Barrier)],

   request: static function (string $hostPort, int $testIndex) use ($Barrier, $Probe): string {
      try {
         // @ Warm the Router synchronously
         $Routed = Client::open($hostPort);
         $Probe->warm = Client::send($Routed, Client::request('/r18/open/0', $testIndex), 4.0);
         // @ A parks in the global middleware, before routing
         $Parked = Client::open($hostPort);
         @fwrite($Parked, Client::request('/r18/g/item/1', $testIndex, ['X-Park' => 'yes']));
         $Probe->held = $Barrier->expect();
         // @ B is routed synchronously while A is parked
         $Probe->routed = Client::send($Routed, Client::request('/r18/open/2', $testIndex), 4.0);
         Client::close($Routed);
         // @ A resumes: routed inside its deferral
         $Barrier->release();
         $Probe->parked = Client::send($Parked, '', 4.0);
         Client::close($Parked);
      }
      catch (Throwable $Throwable) {
         $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /r18/ping HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: static function (Request $Request, Response $Response, Router $Router) use ($PersistentRouter): Response {
      $Handler = function (Request $Request, Response $Response): Response {
         $WPI = WPI;
         $id = $this->Params->id; // @phpstan-ignore variable.undefined
         $path = $this->path; // @phpstan-ignore variable.undefined

         return $Response(body: "id={$id};path={$path};ambient={$WPI->Request->URI}");
      };
      $PersistentRouter->route('/r18/g/item/:id', $Handler, GET);
      $PersistentRouter->route('/r18/open/:id', $Handler, GET);
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
         'warm' => $Probe->warm,
         'held' => $Probe->held,
         'parked' => $Probe->parked,
         'routed' => $Probe->routed
      ]);

      yield new Assertion(
         description: 'The Router warmed synchronously and A parked before routing',
         fallback: "Unexpected warm-up or park: {$evidence}"
      )
         ->expect([$Probe->warm['body'] ?? null, $Probe->held])
         ->to->be(['id=0;path=/r18/open/0;ambient=/r18/open/0', true])
         ->assert();

      yield new Assertion(
         description: 'Request B, routed while A was parked, reads its own params',
         fallback: "Unexpected answer to B: {$evidence}"
      )
         ->expect([$Probe->routed['code'] ?? 0, $Probe->routed['body'] ?? null])
         ->to->be([200, 'id=2;path=/r18/open/2;ambient=/r18/open/2'])
         ->assert();

      yield new Assertion(
         description: "A, routed inside the global middleware's deferral, reads A's params and path",
         fallback: "A's handler read another request's Route state: {$evidence}"
      )
         ->expect([$Probe->parked['code'] ?? 0, $Probe->parked['body'] ?? null])
         ->to->be([200, 'id=1;path=/r18/g/item/1;ambient=/r18/g/item/1'])
         ->assert();
   })
);
