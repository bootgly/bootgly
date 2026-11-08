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
 * An MCP client completes initialize → notifications/initialized →
 * tools/list → tools/call over Streamable HTTP, and the session opened on
 * one worker is resumed on the other: every exchange runs on a fresh
 * connection until a request of the session lands on the second worker.
 * The tool answers with the worker's pid and the call's context (the
 * Request, so the authenticated identity reaches the tool). A session is
 * stored provisionally (at most 60 s) until its first resumed request
 * extends it to the TTL.
 */
$Transport = new MCP(
   new RPC\MCP(
      name: 'Spec',
      version: '1.0.0',
      Lister: static fn (mixed $context): array => [
         ['name' => 'whoami', 'description' => 'Who serves', 'inputSchema' => ['type' => 'object']]
      ],
      Caller: static fn (string $name, array $arguments, mixed $context): array => [
         'content' => [[
            'type' => 'text',
            'text' => (string) json_encode([
               'pid' => getmypid(),
               'identity' => $context instanceof Request ? $context->identity : null,
               'arguments' => $arguments
            ])
         ]],
         'isError' => false
      ]
   ),
   Cache: new Cache(['driver' => 'shared', 'segment' => 0x4d435053, 'prefix' => 'mcp-spec-1.1:'])
);
$Probe = new class {
   /** @var array<string,mixed> */
   public array $initialize = [];
   /** @var array<string,mixed> */
   public array $initialized = [];
   /** @var array<int,array<string,mixed>> */
   public array $lists = [];
   /** @var array<int,array<string,mixed>> */
   public array $calls = [];
   /** @var array<string,int> */
   public array $remain = [];
   public string $error = '';
};
$Store = new Cache(['driver' => 'shared', 'segment' => 0x4d435053, 'prefix' => 'mcp-spec-1.1:']);

return new Test(
   Separator: new Separator(line: ''),

   request: static function (string $hostPort, int $testIndex) use ($Probe, $Store): string {
      try {
         $Probe->initialize = Client::call($hostPort, $testIndex, [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => new stdClass, 'clientInfo' => ['name' => 'spec', 'version' => '1']]
         ], ['X-User' => 'alice']);
         $session = $Probe->initialize['headers']['mcp-session-id'] ?? '';
         $headers = ['X-User' => 'alice', 'Mcp-Session-Id' => $session, 'MCP-Protocol-Version' => '2025-06-18'];
         $Probe->remain['issued'] = $Store->remain("session:{$session}");
         $Probe->initialized = Client::call($hostPort, $testIndex, [
            'jsonrpc' => '2.0', 'method' => 'notifications/initialized'
         ], $headers);
         $Probe->remain['resumed'] = $Store->remain("session:{$session}");
         $first = $Probe->initialize['headers']['x-worker'] ?? '';

         // @@ Until a request of this session lands on the other worker
         for ($attempt = 0; $attempt < 40; $attempt++) {
            $List = Client::call($hostPort, $testIndex, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], $headers);
            $Probe->lists[] = $List;
            if (($List['headers']['x-worker'] ?? $first) !== $first) {
               break;
            }
         }
         for ($attempt = 0; $attempt < 40; $attempt++) {
            $Call = Client::call($hostPort, $testIndex, [
               'jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call',
               'params' => ['name' => 'whoami', 'arguments' => ['n' => 1]]
            ], $headers);
            $Probe->calls[] = $Call;
            if (($Call['headers']['x-worker'] ?? $first) !== $first) {
               break;
            }
         }
      }
      catch (Throwable $Throwable) {
         $Probe->error = "{$Throwable->getMessage()}";
      }

      return "GET /health HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: static function (Request $Request, Response $Response, Router $Router) use ($Transport): Generator {
      yield $Router->route('/mcp', static function (Request $Request, Response $Response) use ($Transport): Response {
         $Request->identity = $Request->Header->get('X-User');
         $Response->Header->set('X-Worker', (string) getmypid());

         return $Transport($Request, $Response);
      }, [GET, POST, DELETE]);
   },

   test: new Assertions(Case: function (string $response) use ($Probe): Generator {
      $Initialize = $Probe->initialize;
      $List = $Probe->lists[count($Probe->lists) - 1] ?? [];
      $Call = $Probe->calls[count($Probe->calls) - 1] ?? [];
      $first = $Initialize['headers']['x-worker'] ?? '';
      $text = json_decode((string) ($Call['json']['result']['content'][0]['text'] ?? ''), true);
      $evidence = json_encode([
         'error' => $Probe->error,
         'initialize' => $Initialize,
         'initialized' => $Probe->initialized,
         'list' => $List,
         'call' => $Call
      ]);

      yield new Assertion(
         description: 'initialize is answered with the agreed revision and a 128-bit session id',
         fallback: "initialize failed: {$evidence}"
      )
         ->expect(
            ($Initialize['code'] ?? 0) === 200
            && ($Initialize['json']['result']['protocolVersion'] ?? '') === '2025-06-18'
            && preg_match('/^[0-9a-f]{32}$/', $Initialize['headers']['mcp-session-id'] ?? '') === 1
         )
         ->to->be(true)
         ->assert();

      $remain = $Probe->remain;
      $remaining = json_encode($remain);
      yield new Assertion(
         description: 'a new session is provisional (<= 60 s) until its first resumed request extends it to the TTL',
         fallback: "remaining seconds: {$remaining}"
      )
         ->expect(
            ($remain['issued'] ?? 0) > 0 && ($remain['issued'] ?? 0) <= 60
            && ($remain['resumed'] ?? 0) > 3000
         )
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'notifications/initialized is accepted with 202 and no body',
         fallback: "notifications/initialized: {$evidence}"
      )
         ->expect([$Probe->initialized['code'] ?? 0, $Probe->initialized['body'] ?? null])
         ->to->be([202, ''])
         ->assert();

      yield new Assertion(
         description: 'tools/list is answered on the OTHER worker with the session',
         fallback: "tools/list did not cross workers: {$evidence}"
      )
         ->expect(
            ($List['code'] ?? 0) === 200
            && ($List['headers']['x-worker'] ?? $first) !== $first
            && ($List['json']['result']['tools'][0]['name'] ?? '') === 'whoami'
         )
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'tools/call is answered on the other worker, with the identity in the context',
         fallback: "tools/call did not cross workers: {$evidence}"
      )
         ->expect(
            ($Call['code'] ?? 0) === 200
            && ($Call['headers']['x-worker'] ?? $first) !== $first
            && is_array($text)
            && (string) $text['pid'] === ($Call['headers']['x-worker'] ?? '')
            && $text['identity'] === 'alice'
            && $text['arguments'] === ['n' => 1]
         )
         ->to->be(true)
         ->assert();
   })
);
