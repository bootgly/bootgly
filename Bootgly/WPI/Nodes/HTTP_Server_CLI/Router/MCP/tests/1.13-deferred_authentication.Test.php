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

use Bootgly\ABI\Resources\Cache;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\API\RPC;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\MCP;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middleware;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authenticating;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication\Bearer;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\MCP\Client;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * Authentication that may defer, in front of the transport: a route
 * middleware defers, then runs the unchanged `Authentication` middleware on
 * the deferral's Request snapshot — its `Bearer` Resolver suspends, as a
 * token lookup in a database does. The identity it resolves is set on the
 * snapshot the transport receives, so the session binds to it on POST, GET
 * and DELETE; refusals keep the guard's 401 challenge; and a tool reads that
 * identity through the ambient `WPI->Response`, the deferred Response.
 */
$Server = new RPC\MCP(
   name: 'Spec',
   version: '1.0.0',
   Lister: static fn (mixed $context): array => [['name' => 'who', 'inputSchema' => ['type' => 'object']]],
   Caller: static function (string $name, array $arguments, mixed $context): array {
      $WPI = WPI;
      $Ambient = $WPI->Response->Request;

      return [
         'content' => [['type' => 'text', 'text' => (string) json_encode([
            'context' => $context instanceof Request ? $context->identity : null,
            'ambient' => $Ambient?->identity,
            'owner' => $Ambient === $context
         ])]],
         'isError' => false
      ];
   }
);
$initialize = [
   'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
   'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => new stdClass, 'clientInfo' => ['name' => 'spec', 'version' => '1']]
];
$call = ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'who', 'arguments' => new stdClass]];
$Transport = new MCP($Server, Cache: new Cache(['driver' => 'shared', 'segment' => 0x4d435053, 'prefix' => 'mcp-spec-1.13:']));
$Bearer = new Bearer(
   Resolver: static function (string $token, Request $Request): mixed {
      // @ The lookup suspends, as a database query does
      $WPI = WPI;
      $WPI->Response->wait();

      return ['tok-alice' => 'alice', 'tok-bob' => 'bob'][$token] ?? null;
   },
   metadata: 'https://rs.example/.well-known/oauth-protected-resource'
);
$Authentication = new Authentication(new Authenticating($Bearer));
// ! The recipe: defer first, then authenticate the snapshot
$Deferring = new class ($Authentication) implements Middleware {
   // * Config
   public readonly Authentication $Authentication;


   public function __construct (Authentication $Authentication)
   {
      // * Config
      $this->Authentication = $Authentication;
   }

   public function process (object $Request, object $Response, Closure $Next): object
   {
      $Authentication = $this->Authentication;

      return $Response->defer(
         static fn (Response $Response, Request $Snapshot): object => $Authentication->process($Snapshot, $Response, $Next)
      );
   }
};
$Probe = new class {
   /** @var array<string,mixed> */
   public array $answers = [];
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),

   request: static function (string $hostPort, int $testIndex) use ($Probe, $initialize, $call): string {
      $bearer = static fn (string $token, array $headers = []): array => ['Authorization' => "Bearer {$token}"] + $headers;
      try {
         $A = [];
         $None = Client::call($hostPort, $testIndex, $initialize);
         $A['none'] = [$None['code'], $None['headers']['www-authenticate'] ?? ''];
         $Bad = Client::call($hostPort, $testIndex, $initialize, $bearer('tok-nobody'));
         $A['bad'] = [$Bad['code'], $Bad['headers']['www-authenticate'] ?? ''];

         $Init = Client::call($hostPort, $testIndex, $initialize, $bearer('tok-alice'));
         $session = ['Mcp-Session-Id' => $Init['headers']['mcp-session-id'] ?? ''];
         $A['init'] = [$Init['code'], $session['Mcp-Session-Id'] !== ''];
         $Call = Client::call($hostPort, $testIndex, $call, $bearer('tok-alice', $session));
         $content = $Call['json']['result']['content'][0]['text'] ?? '';
         $A['call'] = [$Call['code'], json_decode(is_string($content) ? $content : '', true)];

         $A['bob'] = [
            Client::call($hostPort, $testIndex, $call, $bearer('tok-bob', $session))['code'],
            Client::exchange($hostPort, $testIndex, 'GET', $bearer('tok-bob', $session + ['Accept' => 'text/event-stream']), timeout: 1.0)['code'],
            Client::exchange($hostPort, $testIndex, 'DELETE', $bearer('tok-bob', $session))['code']
         ];

         $Stream = Client::exchange($hostPort, $testIndex, 'GET', $bearer('tok-alice', $session + ['Accept' => 'text/event-stream']), timeout: 1.0);
         $A['stream'] = [$Stream['code'], $Stream['headers']['content-type'] ?? '', $Stream['closed']];
         $A['delete'] = Client::exchange($hostPort, $testIndex, 'DELETE', $bearer('tok-alice', $session))['code'];

         $Probe->answers = $A;
      }
      catch (Throwable $Throwable) {
         $Probe->error = "{$Throwable->getMessage()}";
      }

      return "GET /health HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: static function (Request $Request, Response $Response, Router $Router) use ($Transport, $Deferring): Generator {
      yield $Router->route('/mcp', static function (Request $Request, Response $Response) use ($Transport): Response {
         // @ Answer on the Response received — behind a deferring middleware
         //   a new Response returned here would not be adopted
         return $Transport($Request, $Response);
      }, [GET, POST, DELETE], middlewares: [$Deferring]);
   },

   test: new Assertions(Case: function (string $response) use ($Probe): Generator {
      $A = $Probe->answers;
      $evidence = json_encode(['error' => $Probe->error, 'answers' => $A]);
      $metadata = 'resource_metadata="https://rs.example/.well-known/oauth-protected-resource"';

      yield new Assertion(
         description: 'no credentials and a refused token get the guard\'s 401 challenge',
         fallback: "Failed — {$evidence}"
      )
         ->expect([
            $A['none'][0] ?? 0,
            str_contains((string) ($A['none'][1] ?? ''), $metadata),
            $A['bad'][0] ?? 0,
            str_contains((string) ($A['bad'][1] ?? ''), 'error="invalid_token"')
         ])
         ->to->be([401, true, 401, true])
         ->assert();

      yield new Assertion(
         description: 'alice opens a session and calls a tool that reads her identity through WPI->Response',
         fallback: "Failed — {$evidence}"
      )
         ->expect([$A['init'] ?? null, $A['call'] ?? null])
         ->to->be([[200, true], [200, ['context' => 'alice', 'ambient' => 'alice', 'owner' => true]]])
         ->assert();

      yield new Assertion(
         description: 'the session is bound to alice: bob gets 404 on POST, GET and DELETE',
         fallback: "Failed — {$evidence}"
      )
         ->expect($A['bob'] ?? null)
         ->to->be([404, 404, 404])
         ->assert();

      yield new Assertion(
         description: 'alice opens the GET stream, then ends the session with DELETE',
         fallback: "Failed — {$evidence}"
      )
         ->expect([$A['stream'] ?? null, $A['delete'] ?? 0])
         ->to->be([[200, 'text/event-stream', false], 204])
         ->assert();
   })
);
