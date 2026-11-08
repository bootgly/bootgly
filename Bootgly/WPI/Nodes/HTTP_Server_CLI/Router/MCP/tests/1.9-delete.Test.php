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
 * DELETE ends a session: only its own principal may end it (404 for
 * another), then 204; afterwards every request naming it is 404 — DELETE
 * included — and a new initialize opens another.
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
$Transport = new MCP($Server, Cache: new Cache(['driver' => 'shared', 'segment' => 0x4d435053, 'prefix' => 'mcp-spec-1.9:']));
$Probe = new class {
   /** @var array<string,mixed> */
   public array $answers = [];
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),

   request: static function (string $hostPort, int $testIndex) use ($Probe, $initialize, $list): string {
      try {
         $Init = Client::call($hostPort, $testIndex, $initialize, ['X-User' => 'alice']);
         $session = ['X-User' => 'alice', 'Mcp-Session-Id' => $Init['headers']['mcp-session-id'] ?? ''];
         $Probe->answers['other'] = Client::exchange($hostPort, $testIndex, 'DELETE', ['X-User' => 'bob'] + $session)['code'];
         $Probe->answers['missing'] = Client::exchange($hostPort, $testIndex, 'DELETE', ['X-User' => 'alice'])['code'];
         $Delete = Client::exchange($hostPort, $testIndex, 'DELETE', $session);
         $Probe->answers['delete'] = [$Delete['code'], $Delete['body']];
         $Probe->answers['after'] = Client::call($hostPort, $testIndex, $list, $session)['code'];
         $Probe->answers['again'] = Client::exchange($hostPort, $testIndex, 'DELETE', $session)['code'];
         $Probe->answers['reopen'] = Client::call($hostPort, $testIndex, $initialize, ['X-User' => 'alice'])['code'];
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
         description: 'another principal cannot end the session; no id is 400',
         fallback: "Failed — {$evidence}"
      )
         ->expect([$A['other'], $A['missing']])
         ->to->be([404, 400])
         ->assert();

      yield new Assertion(
         description: 'DELETE ends the session with 204 and no body',
         fallback: "Failed — {$evidence}"
      )
         ->expect($A['delete'])
         ->to->be([204, ''])
         ->assert();

      yield new Assertion(
         description: 'the ended session is 404 for every request',
         fallback: "Failed — {$evidence}"
      )
         ->expect([$A['after'], $A['again']])
         ->to->be([404, 404])
         ->assert();

      yield new Assertion(
         description: 'a new initialize opens another session',
         fallback: "Failed — {$evidence}"
      )
         ->expect($A['reopen'])
         ->to->be(200)
         ->assert();

   })
);
