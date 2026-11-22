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
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\MCP\Client;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * The nested handoff: the route handler defers, resolves the identity in
 * deferred work (the lookup suspends), sets it on the deferral's Request
 * snapshot and invokes the transport from inside — which defers again. The
 * session binds to that identity; a tool reads it through the ambient
 * `WPI->Response`; a request with no known token never reaches the
 * transport.
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
$Transport = new MCP($Server, Cache: new Cache(['driver' => 'shared', 'segment' => 0x4d435053, 'prefix' => 'mcp-spec-1.14:']));
$Probe = new class {
   /** @var array<string,mixed> */
   public array $answers = [];
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),

   request: static function (string $hostPort, int $testIndex) use ($Probe, $initialize, $call): string {
      try {
         $A = [];
         $A['none'] = Client::call($hostPort, $testIndex, $initialize)['code'];
         $Init = Client::call($hostPort, $testIndex, $initialize, ['X-Token' => 'tok-alice']);
         $session = ['Mcp-Session-Id' => $Init['headers']['mcp-session-id'] ?? ''];
         $A['init'] = [$Init['code'], $session['Mcp-Session-Id'] !== ''];
         $Call = Client::call($hostPort, $testIndex, $call, ['X-Token' => 'tok-alice'] + $session);
         $content = $Call['json']['result']['content'][0]['text'] ?? '';
         $A['call'] = [$Call['code'], json_decode(is_string($content) ? $content : '', true)];
         $A['bob'] = [
            Client::call($hostPort, $testIndex, $call, ['X-Token' => 'tok-bob'] + $session)['code'],
            Client::exchange($hostPort, $testIndex, 'DELETE', ['X-Token' => 'tok-bob'] + $session)['code']
         ];
         $A['delete'] = Client::exchange($hostPort, $testIndex, 'DELETE', ['X-Token' => 'tok-alice'] + $session)['code'];

         $Probe->answers = $A;
      }
      catch (Throwable $Throwable) {
         $Probe->error = "{$Throwable->getMessage()}";
      }

      return "GET /health HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: static function (Request $Request, Response $Response, Router $Router) use ($Transport): Generator {
      yield $Router->route('/mcp', static function (Request $Request, Response $Response) use ($Transport): Response {
         return $Response->defer(static function (Response $Response, Request $Snapshot) use ($Transport): void {
            // @ The lookup suspends, as a database query does
            $Response->wait();
            $token = (string) $Snapshot->Header->get('X-Token');
            $identity = ['tok-alice' => 'alice', 'tok-bob' => 'bob'][$token] ?? null;
            if ($identity === null) {
               $Response(code: 401, body: '');

               return;
            }

            // @ The identity goes on the snapshot, never on the live Request
            $Snapshot->identity = $identity;
            $Transport($Snapshot, $Response);
         });
      }, [GET, POST, DELETE]);
   },

   test: new Assertions(Case: function (string $response) use ($Probe): Generator {
      $A = $Probe->answers;
      $evidence = json_encode(['error' => $Probe->error, 'answers' => $A]);

      yield new Assertion(
         description: 'a request with no known token never reaches the transport',
         fallback: "Failed — {$evidence}"
      )
         ->expect($A['none'] ?? 0)
         ->to->be(401)
         ->assert();

      yield new Assertion(
         description: 'alice opens a session from inside the deferral; a tool reads her identity through WPI->Response',
         fallback: "Failed — {$evidence}"
      )
         ->expect([$A['init'] ?? null, $A['call'] ?? null])
         ->to->be([[200, true], [200, ['context' => 'alice', 'ambient' => 'alice', 'owner' => true]]])
         ->assert();

      yield new Assertion(
         description: 'the session is bound to alice: bob gets 404, alice ends it with 204',
         fallback: "Failed — {$evidence}"
      )
         ->expect([$A['bob'] ?? null, $A['delete'] ?? 0])
         ->to->be([[404, 404], 204])
         ->assert();
   })
);
