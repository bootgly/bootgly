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
 * Idle expiry slides: each request renews the session for `TTL` seconds, so
 * a client active past the first TTL keeps it; after `TTL` of silence the
 * session is gone (404) and the client must initialize again. The endpoint
 * is JSON-only (`streams: 0`): with `GET` streams enabled a session lives at
 * least a stream's lease (15 s).
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
$Transport = new MCP($Server, Cache: new Cache(['driver' => 'shared', 'segment' => 0x4d435053, 'prefix' => 'mcp-spec-1.3:']), TTL: 3, streams: 0);
$Probe = new class {
   /** @var array<string,mixed> */
   public array $answers = [];
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),

   request: static function (string $hostPort, int $testIndex) use ($Probe, $initialize, $list): string {
      try {
         $Init = Client::call($hostPort, $testIndex, $initialize);
         $session = ['Mcp-Session-Id' => $Init['headers']['mcp-session-id'] ?? ''];
         $codes = [];
         // @@ Active for longer than one TTL, 1 s apart (whole-second expiry: >= 1 s of slack)
         for ($step = 0; $step < 4; $step++) {
            usleep(1_000_000);
            $codes[] = Client::call($hostPort, $testIndex, $list, $session)['code'];
         }
         $Probe->answers['active'] = $codes;
         usleep(4_500_000);
         $Probe->answers['idle'] = Client::call($hostPort, $testIndex, $list, $session)['code'];
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
         description: 'requests within the TTL keep renewing the session past its first TTL',
         fallback: "Failed — {$evidence}"
      )
         ->expect($A['active'])
         ->to->be([200, 200, 200, 200])
         ->assert();

      yield new Assertion(
         description: 'after TTL seconds of silence the session is gone: 404',
         fallback: "Failed — {$evidence}"
      )
         ->expect($A['idle'])
         ->to->be(404)
         ->assert();

   })
);
