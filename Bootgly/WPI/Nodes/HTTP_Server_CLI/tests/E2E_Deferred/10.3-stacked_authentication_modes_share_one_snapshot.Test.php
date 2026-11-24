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
 * A global `Authentication` in the deferring mode stacked with a route one
 * defers once: the route middleware, reached inside the global deferral,
 * runs its guards inline on the same snapshot — no second capture. The
 * handler, on a dynamic route of a persistent Router warmed synchronously
 * and resolved inside the deferral, reads its own params.
 */
$PersistentRouter = new Router;
$Seen = new class {
   /** @var array<int,int> */
   public array $ids = [];
};
$Bearer = new Bearer(
   Resolver: static function (string $token, Request $Request) use ($Seen): mixed {
      $WPI = WPI;
      $Seen->ids[] = spl_object_id($Request);
      Routes::park($WPI->Response, 0.05);

      return $token === 'tok-alice' ? 'alice' : null;
   }
);
$Global = new Authentication(new Authenticating($Bearer), deferred: true, timeout: 3);
$Route = new Authentication(new Authenticating($Bearer), deferred: true, timeout: 3);
$Probe = new class {
   /** @var array<string,array<string,mixed>> */
   public array $answers = [];
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),
   middlewares: [$Global],

   request: static function (string $hostPort, int $testIndex) use ($Probe): string {
      $requests = [
         'warm' => '/am3/item/0',
         'first' => '/am3/item/1',
         'second' => '/am3/item/2'
      ];
      try {
         foreach ($requests as $key => $path) {
            $Socket = Client::open($hostPort);
            $Probe->answers[$key] = Client::send($Socket, Client::request($path, $testIndex, ['Authorization' => 'Bearer tok-alice']), 4.0);
            Client::close($Socket);
         }
      }
      catch (Throwable $Throwable) {
         $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /am3/ping HTTP/1.1\r\nHost: localhost\r\nAuthorization: Bearer tok-alice\r\n\r\n";
   },
   response: static function (Request $Request, Response $Response, Router $Router) use ($PersistentRouter, $Route, $Seen): Response {
      $Handler = function (Request $Request, Response $Response) use ($Seen): Response {
         $id = $this->Params->id; // @phpstan-ignore variable.undefined
         $ids = $Seen->ids;
         $Seen->ids = [];
         $Response->JSON->send([
            'id' => $id,
            'identity' => $Request->identity,
            'resolved' => count($ids),
            'one' => array_unique([...$ids, spl_object_id($Request)]) === [spl_object_id($Request)]
         ]);

         return $Response;
      };
      $PersistentRouter->route('/am3/item/:id', $Handler, GET, middlewares: [$Route]);
      $PersistentRouter->route('/am3/ping', static function (Request $Request, Response $Response): Response {
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
      $A = $Probe->answers;
      $evidence = json_encode(['error' => $Probe->error, 'answers' => $A]);
      $read = static function (string $key) use ($A): array {
         $body = json_decode((string) ($A[$key]['body'] ?? ''), true);

         return [$A[$key]['code'] ?? 0, is_array($body) ? $body : []];
      };

      foreach (['warm' => '0', 'first' => '1', 'second' => '2'] as $key => $id) {
         yield new Assertion(
            description: "Request {$key}: both guard chains ran on the one snapshot the handler reads, with its own params",
            fallback: "The stacked modes captured more than one snapshot: {$evidence}"
         )
            ->expect($read($key))
            ->to->be([200, ['id' => $id, 'identity' => 'alice', 'resolved' => 2, 'one' => true]])
            ->assert();
      }
   })
);
