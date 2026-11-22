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
 * RTR-18: a handler behind a route middleware that defers ahead of `$next`
 * reads ITS request's route params and path — not those of a request the
 * Router resolved on the same route while the deferral was parked.
 *
 * The Router binds every handler once, to its one Route; the deferred
 * generation swaps its own Route state into that Route per Fiber segment.
 * Test mode gives every request a fresh Router: this spec installs one
 * persistent Router (the Security 55.01 idiom) — the production shape.
 */
$PersistentRouter = new Router;
$Barrier = new Barrier;
// ! Route middleware: defers request A only (its URI is the prefix)
$Defer = new Deferrer('/r18/mw/item/1', Barrier: $Barrier);
$Probe = new class {
   public bool $held = false;
   /** @var array<string,mixed> */
   public array $parked = [];
   /** @var array<string,mixed> */
   public array $routed = [];
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),

   request: static function (string $hostPort, int $testIndex) use ($Barrier, $Probe): string {
      try {
         // @ A parks inside the route middleware's deferral
         $Parked = Client::open($hostPort);
         @fwrite($Parked, Client::request('/r18/mw/item/1', $testIndex, ['X-Park' => 'yes']));
         $Probe->held = $Barrier->expect();
         // @ B is routed on the same route, synchronously, while A is parked
         $Routed = Client::open($hostPort);
         $Probe->routed = Client::send($Routed, Client::request('/r18/mw/item/2', $testIndex), 4.0);
         Client::close($Routed);
         // @ A resumes and calls `$next`
         $Barrier->release();
         $Probe->parked = Client::send($Parked, '', 4.0);
         Client::close($Parked);
      }
      catch (Throwable $Throwable) {
         $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /r18/ping HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: static function (Request $Request, Response $Response, Router $Router) use ($PersistentRouter, $Defer): Response {
      // ! One Route across requests, as the production Encoder_ path has
      $WPI = WPI;
      $WPI->Router = $PersistentRouter;

      $PersistentRouter->route('/r18/mw/item/:id', function (Request $Request, Response $Response): Response {
         $WPI = WPI;
         $id = $this->Params->id; // @phpstan-ignore variable.undefined
         $path = $this->path; // @phpstan-ignore variable.undefined

         return $Response(body: "id={$id};path={$path};ambient={$WPI->Request->URI}");
      }, GET, middlewares: [$Defer]);
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
         'held' => $Probe->held,
         'parked' => $Probe->parked,
         'routed' => $Probe->routed
      ]);

      yield new Assertion(
         description: 'Request A parked inside the route middleware before B was routed',
         fallback: "The deferral never reported its park: {$evidence}"
      )
         ->expect($Probe->held)
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'Request B, routed while A was parked, reads its own params',
         fallback: "Unexpected answer to B: {$evidence}"
      )
         ->expect([$Probe->routed['code'] ?? 0, $Probe->routed['body'] ?? null])
         ->to->be([200, 'id=2;path=/r18/mw/item/2;ambient=/r18/mw/item/2'])
         ->assert();

      yield new Assertion(
         description: "A's handler reads A's route params and path, not B's",
         fallback: "A's handler read another request's Route state: {$evidence}"
      )
         ->expect([$Probe->parked['code'] ?? 0, $Probe->parked['body'] ?? null])
         ->to->be([200, 'id=1;path=/r18/mw/item/1;ambient=/r18/mw/item/1'])
         ->assert();
   })
);
