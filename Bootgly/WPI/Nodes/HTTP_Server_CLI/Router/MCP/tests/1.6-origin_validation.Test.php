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
 * Origin validation (DNS rebinding): a present Origin must match the
 * allowlist exactly (case and a trailing slash normalized) — never derived
 * from the request Host, so a rebinding page whose Origin equals the Host
 * is refused; `null` is refused; a missing Origin (non-browser client) is
 * served. A refused DELETE leaves the session alive.
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
$Transport = new MCP($Server, origins: ['https://App.Example.com/'], Cache: new Cache(['driver' => 'shared', 'segment' => 0x4d435053, 'prefix' => 'mcp-spec-1.6:']));
$Probe = new class {
   /** @var array<string,mixed> */
   public array $answers = [];
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),

   request: static function (string $hostPort, int $testIndex) use ($Probe, $initialize, $list): string {
      try {
         $Allowed = Client::call($hostPort, $testIndex, $initialize, ['Origin' => 'https://app.example.com']);
         $session = ['Mcp-Session-Id' => $Allowed['headers']['mcp-session-id'] ?? ''];
         $Probe->answers['allowed'] = $Allowed['code'];
         $Probe->answers['trailing'] = Client::call($hostPort, $testIndex, $list, $session + ['Origin' => 'https://APP.example.com/'])['code'];
         $Foreign = Client::call($hostPort, $testIndex, $initialize, ['Origin' => 'https://evil.example']);
         $Probe->answers['foreign'] = [$Foreign['code'], isSet($Foreign['headers']['mcp-session-id']), $Foreign['json']['error']['code'] ?? null];
         $Probe->answers['null'] = Client::call($hostPort, $testIndex, $initialize, ['Origin' => 'null'])['code'];
         $Probe->answers['host'] = Client::call($hostPort, $testIndex, $initialize, ['Origin' => "http://{$hostPort}"])['code'];
         $Probe->answers['port'] = Client::call($hostPort, $testIndex, $initialize, ['Origin' => 'https://app.example.com:8443'])['code'];
         $Probe->answers['missing'] = Client::call($hostPort, $testIndex, $initialize)['code'];
         $Probe->answers['delete'] = Client::exchange($hostPort, $testIndex, 'DELETE', $session + ['Origin' => 'https://evil.example'])['code'];
         $Probe->answers['get'] = Client::exchange($hostPort, $testIndex, 'GET', $session + ['Origin' => 'https://evil.example', 'Accept' => 'text/event-stream'])['code'];
         $Probe->answers['alive'] = Client::call($hostPort, $testIndex, $list, $session)['code'];
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
         description: 'an allowlisted Origin is served (case and trailing slash normalized)',
         fallback: "Failed — {$evidence}"
      )
         ->expect([$A['allowed'], $A['trailing']])
         ->to->be([200, 200])
         ->assert();

      yield new Assertion(
         description: 'a foreign Origin is 403, opens no session and says why as a JSON-RPC error',
         fallback: "Failed — {$evidence}"
      )
         ->expect($A['foreign'])
         ->to->be([403, false, -32000])
         ->assert();

      yield new Assertion(
         description: 'Origin null, the Host itself and another port are refused',
         fallback: "Failed — {$evidence}"
      )
         ->expect([$A['null'], $A['host'], $A['port']])
         ->to->be([403, 403, 403])
         ->assert();

      yield new Assertion(
         description: 'a missing Origin (non-browser client) is served',
         fallback: "Failed — {$evidence}"
      )
         ->expect($A['missing'])
         ->to->be(200)
         ->assert();

      yield new Assertion(
         description: 'a foreign Origin cannot end or listen to the session',
         fallback: "Failed — {$evidence}"
      )
         ->expect([$A['delete'], $A['get'], $A['alive']])
         ->to->be([403, 403, 200])
         ->assert();

   })
);
