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
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authenticating;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication\Bearer;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Deferred\Deferrer;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Deferred\Routes;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Idle\Client;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * The deferring mode behind a global middleware that already deferred
 * (not an `Authentication`): the route middleware, reached inside that
 * deferral on a persistent Router warmed there, defers again — its guards
 * run on their own snapshot, active, which the handler receives with its
 * own params. A refused token and a request without credentials are
 * answered by the challenge on the deferred Response.
 */
$PersistentRouter = new Router;
$Bearer = new Bearer(
   Resolver: static function (string $token, Request $Request): mixed {
      $WPI = WPI;
      Routes::park($WPI->Response, 0.05);

      return $token === 'tok-alice' ? 'alice' : null;
   }
);
$Mode = new Authentication(new Authenticating($Bearer), deferred: true, timeout: 3);
$Probe = new class {
   /** @var array<string,array<string,mixed>> */
   public array $answers = [];
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),
   middlewares: [new Deferrer('/am4/g/', $PersistentRouter)],

   request: static function (string $hostPort, int $testIndex) use ($Probe): string {
      $requests = [
         'first' => ['/am4/g/item/1', 'tok-alice'],
         'second' => ['/am4/g/item/2', 'tok-alice'],
         'refused' => ['/am4/g/item/3', 'tok-nobody'],
         'none' => ['/am4/g/item/4', '']
      ];
      try {
         foreach ($requests as $key => [$path, $token]) {
            $headers = $token === '' ? [] : ['Authorization' => "Bearer {$token}"];
            $Socket = Client::open($hostPort);
            $Probe->answers[$key] = Client::send($Socket, Client::request($path, $testIndex, $headers), 4.0);
            Client::close($Socket);
         }
      }
      catch (Throwable $Throwable) {
         $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /am4/ping HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: static function (Request $Request, Response $Response, Router $Router) use ($PersistentRouter, $Mode): Response {
      $Handler = function (Request $Request, Response $Response): Response {
         $WPI = WPI;
         $id = $this->Params->id; // @phpstan-ignore variable.undefined
         $Generation = Cancellation::fetch($Request);
         $Response->JSON->send([
            'id' => $id,
            'identity' => $Request->identity,
            'ambient' => $WPI->Response->Request === $Request,
            'snapshot' => $Generation !== null && $Generation->check() === false
         ]);

         return $Response;
      };
      $PersistentRouter->route('/am4/g/item/:id', $Handler, GET, middlewares: [$Mode]);
      $PersistentRouter->route('/am4/ping', static function (Request $Request, Response $Response): Response {
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
      $challenge = static function (string $key) use ($A): string {
         $matches = [];
         preg_match('/\r\nWWW-Authenticate: ([^\r\n]*)/i', (string) ($A[$key]['head'] ?? ''), $matches);

         return $matches[1] ?? '';
      };

      foreach (['first' => '1', 'second' => '2'] as $key => $id) {
         yield new Assertion(
            description: "Request {$key}: authenticated on its own active snapshot under the foreign deferral, with its own params",
            fallback: "The nested deferral did not carry the request: {$evidence}"
         )
            ->expect($read($key))
            ->to->be([200, ['id' => $id, 'identity' => 'alice', 'ambient' => true, 'snapshot' => true]])
            ->assert();
      }

      yield new Assertion(
         description: 'A refused token under the foreign deferral gets the invalid_token 401',
         fallback: "Unexpected refusal: {$evidence}"
      )
         ->expect([$A['refused']['code'] ?? 0, $challenge('refused')])
         ->to->be([401, 'Bearer realm="Protected area", error="invalid_token"'])
         ->assert();

      yield new Assertion(
         description: 'No credentials under the foreign deferral gets the invitation 401',
         fallback: "Unexpected invitation: {$evidence}"
      )
         ->expect([$A['none']['code'] ?? 0, $challenge('none')])
         ->to->be([401, 'Bearer realm="Protected area"'])
         ->assert();
   })
);
