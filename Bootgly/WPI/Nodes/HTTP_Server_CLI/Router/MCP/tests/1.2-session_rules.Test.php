<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


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
 * Sessions: `initialize` issues a fresh 128-bit id — never the one a client
 * sends — and every later request must carry it: 400 when missing, 404 when
 * malformed, unknown or opened by another principal; `MCP-Protocol-Version`,
 * when sent, must equal the revision agreed at `initialize` (400).
 */
$Server = new RPC\MCP(
   name: 'Spec',
   version: '1.0.0',
   Lister: static fn (mixed $context): array => [['name' => 'echo', 'inputSchema' => ['type' => 'object']]],
   Caller: static fn (string $name, array $arguments, mixed $context): array => [
      'content' => [['type' => 'text', 'text' => (string) json_encode($arguments)]],
      'isError' => false
   ]
);
$initialize = [
   'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
   'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => new stdClass, 'clientInfo' => ['name' => 'spec', 'version' => '1']]
];
$list = ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'];
$Transport = new MCP($Server, Cache: new Cache(['driver' => 'shared', 'segment' => 0x4d435053, 'prefix' => 'mcp-spec-1.2:']));
$Probe = new class {
   /** @var array<string,mixed> */
   public array $answers = [];
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),

   request: static function (string $hostPort, int $testIndex) use ($Probe, $initialize, $list): string {
      try {
         $alice = ['X-User' => 'alice'];
         $Init = Client::call($hostPort, $testIndex, $initialize, $alice);
         $id = $Init['headers']['mcp-session-id'] ?? '';
         $Probe->answers['init'] = [$Init['code'], $id];
         $Probe->answers['missing'] = Client::call($hostPort, $testIndex, $list, $alice)['code'];
         $Probe->answers['malformed'] = Client::call($hostPort, $testIndex, $list, $alice + ['Mcp-Session-Id' => 'xyz'])['code'];
         $Probe->answers['unknown'] = Client::call($hostPort, $testIndex, $list, $alice + ['Mcp-Session-Id' => str_repeat('a', 32)])['code'];
         $Probe->answers['uppercase'] = Client::call($hostPort, $testIndex, $list, $alice + ['Mcp-Session-Id' => strtoupper($id)])['code'];
         $Probe->answers['other'] = Client::call($hostPort, $testIndex, $list, ['X-User' => 'bob', 'Mcp-Session-Id' => $id])['code'];
         $Probe->answers['anonymous'] = Client::call($hostPort, $testIndex, $list, ['Mcp-Session-Id' => $id])['code'];
         $Probe->answers['revision'] = Client::call($hostPort, $testIndex, $list, $alice + ['Mcp-Session-Id' => $id, 'MCP-Protocol-Version' => '2025-03-26'])['code'];
         $Probe->answers['agreed'] = Client::call($hostPort, $testIndex, $list, $alice + ['Mcp-Session-Id' => $id, 'MCP-Protocol-Version' => '2025-06-18'])['code'];
         $Probe->answers['unversioned'] = Client::call($hostPort, $testIndex, $list, $alice + ['Mcp-Session-Id' => $id])['code'];
         $Chosen = Client::call($hostPort, $testIndex, $initialize, $alice + ['Mcp-Session-Id' => str_repeat('b', 32)]);
         $Probe->answers['chosen'] = $Chosen['headers']['mcp-session-id'] ?? '';
         $Probe->answers['batch'] = Client::exchange($hostPort, $testIndex, 'POST', $alice + ['Content-Type' => 'application/json', 'Accept' => 'application/json'], (string) json_encode([$initialize]))['code'];
      }
      catch (Throwable $Throwable) {
         $Probe->error = "{$Throwable->getMessage()}";
      }

      return "GET /health HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: static function (Request $Request, Response $Response, Router $Router) use ($Transport): Generator {
      yield $Router->route('/mcp', static function (Request $Request, Response $Response) use ($Transport): Response {
         $Request->identity = $Request->Header->get('X-User');

         return $Transport($Request, $Response);
      }, [GET, POST, DELETE, PUT]);
   },

   test: new Assertions(Case: function (string $response) use ($Probe): Generator {
      $A = $Probe->answers;
      $evidence = json_encode(['error' => $Probe->error, 'answers' => $A]);

      yield new Assertion(
         description: 'initialize answers 200 with a 32-hex session id',
         fallback: "Failed — {$evidence}"
      )
         ->expect($A['init'][0] === 200 && preg_match('/^[0-9a-f]{32}$/', $A['init'][1]) === 1)
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'a request without Mcp-Session-Id is refused with 400',
         fallback: "Failed — {$evidence}"
      )
         ->expect($A['missing'])
         ->to->be(400)
         ->assert();

      yield new Assertion(
         description: 'a malformed id is refused with 404 before any store lookup',
         fallback: "Failed — {$evidence}"
      )
         ->expect([$A['malformed'], $A['uppercase']])
         ->to->be([404, 404])
         ->assert();

      yield new Assertion(
         description: 'an unknown id is refused with 404',
         fallback: "Failed — {$evidence}"
      )
         ->expect($A['unknown'])
         ->to->be(404)
         ->assert();

      yield new Assertion(
         description: 'another principal (or none) cannot drive the session: 404',
         fallback: "Failed — {$evidence}"
      )
         ->expect([$A['other'], $A['anonymous']])
         ->to->be([404, 404])
         ->assert();

      yield new Assertion(
         description: 'a MCP-Protocol-Version other than the agreed revision is refused with 400',
         fallback: "Failed — {$evidence}"
      )
         ->expect($A['revision'])
         ->to->be(400)
         ->assert();

      yield new Assertion(
         description: 'the agreed revision, or none sent, is served',
         fallback: "Failed — {$evidence}"
      )
         ->expect([$A['agreed'], $A['unversioned']])
         ->to->be([200, 200])
         ->assert();

      yield new Assertion(
         description: 'a client-chosen id is never adopted; each initialize issues a new one',
         fallback: "Failed — {$evidence}"
      )
         ->expect($A['chosen'] !== str_repeat('b', 32) && $A['chosen'] !== $A['init'][1] && strlen($A['chosen']) === 32)
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'a batch carrying initialize opens no session: 400 without an id',
         fallback: "Failed — {$evidence}"
      )
         ->expect($A['batch'])
         ->to->be(400)
         ->assert();

   })
);
