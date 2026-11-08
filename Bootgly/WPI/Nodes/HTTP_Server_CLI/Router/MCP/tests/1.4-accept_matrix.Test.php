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
 * Accept negotiation on POST: the answer is `application/json` or one
 * `message` event on a `text/event-stream` that ends with it — whichever
 * the client prefers; no Accept means JSON; neither acceptable is 406. A
 * body that is not `application/json` is 415, and only notifications get
 * 202 with no body. `initialize` answered on a stream still carries the id.
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
$Transport = new MCP($Server, Cache: new Cache(['driver' => 'shared', 'segment' => 0x4d435053, 'prefix' => 'mcp-spec-1.4:']));
$Probe = new class {
   /** @var array<string,mixed> */
   public array $answers = [];
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),

   request: static function (string $hostPort, int $testIndex) use ($Probe, $initialize, $list): string {
      try {
         $Init = Client::call($hostPort, $testIndex, $initialize, ['Accept' => 'text/event-stream']);
         $Probe->answers['init'] = [
            $Init['headers']['content-type'] ?? '',
            $Init['json']['result']['protocolVersion'] ?? '',
            strlen($Init['headers']['mcp-session-id'] ?? '')
         ];
         $session = ['Mcp-Session-Id' => $Init['headers']['mcp-session-id'] ?? ''];
         $Shape = static fn (array $Answer): array => [
            $Answer['code'],
            explode(';', $Answer['headers']['content-type'] ?? '')[0],
            $Answer['json']['id'] ?? null
         ];
         foreach ([
            'json' => 'application/json',
            'sse' => 'text/event-stream',
            'prefer_sse' => 'text/event-stream, application/json;q=0.5',
            'prefer_json' => 'application/json, text/event-stream',
            'tie_sse' => 'text/event-stream, application/json',
            'wildcard' => '*/*',
            'neither' => 'text/html',
            'refused' => 'application/json;q=0, text/event-stream;q=0'
         ] as $case => $accept) {
            $Probe->answers[$case] = $Shape(Client::call($hostPort, $testIndex, $list, $session + ['Accept' => $accept]));
         }
         $Stream = Client::call($hostPort, $testIndex, $list, $session + ['Accept' => 'text/event-stream']);
         $Probe->answers['event'] = [str_contains($Stream['body'], "event: message\n"), $Stream['closed'] || str_ends_with($Stream['body'], "\n\n")];
         $Probe->answers['none'] = Client::exchange($hostPort, $testIndex, 'POST', $session + ['Content-Type' => 'application/json'], (string) json_encode($list))['code'];
         $Probe->answers['type'] = Client::exchange($hostPort, $testIndex, 'POST', $session + ['Content-Type' => 'text/plain', 'Accept' => 'application/json'], (string) json_encode($list))['code'];
         $Probe->answers['form'] = Client::exchange($hostPort, $testIndex, 'POST', $session + ['Content-Type' => 'application/x-www-form-urlencoded', 'Accept' => 'application/json'], 'a=1')['code'];
         $Notification = Client::call($hostPort, $testIndex, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $session);
         $Probe->answers['notification'] = [$Notification['code'], $Notification['body']];
         // @ HTTP/1.0 cannot stream: JSON when acceptable, otherwise 406
         $Probe->answers['legacy'] = [
            $Shape(Client::call($hostPort, $testIndex, $list, $session + ['Accept' => 'text/event-stream, application/json'], 'HTTP/1.0')),
            Client::call($hostPort, $testIndex, $list, $session + ['Accept' => 'text/event-stream'], 'HTTP/1.0')['code']
         ];
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
         description: 'initialize answered on a stream carries the session id',
         fallback: "Failed — {$evidence}"
      )
         ->expect($A['init'])
         ->to->be(['text/event-stream', '2025-06-18', 32])
         ->assert();

      yield new Assertion(
         description: 'Accept application/json answers JSON',
         fallback: "Failed — {$evidence}"
      )
         ->expect($A['json'])
         ->to->be([200, 'application/json', 2])
         ->assert();

      yield new Assertion(
         description: 'Accept text/event-stream answers one event on a stream',
         fallback: "Failed — {$evidence}"
      )
         ->expect([$A['sse'], $A['event']])
         ->to->be([[200, 'text/event-stream', 2], [true, true]])
         ->assert();

      yield new Assertion(
         description: "the client's preference decides when both are acceptable (the first listed on a tie)",
         fallback: "Failed — {$evidence}"
      )
         ->expect([$A['prefer_sse'][1], $A['prefer_json'][1], $A['tie_sse'][1], $A['wildcard'][1]])
         ->to->be(['text/event-stream', 'application/json', 'text/event-stream', 'application/json'])
         ->assert();

      yield new Assertion(
         description: 'neither form acceptable is 406',
         fallback: "Failed — {$evidence}"
      )
         ->expect([$A['neither'][0], $A['refused'][0]])
         ->to->be([406, 406])
         ->assert();

      yield new Assertion(
         description: 'no Accept header answers JSON',
         fallback: "Failed — {$evidence}"
      )
         ->expect($A['none'])
         ->to->be(200)
         ->assert();

      yield new Assertion(
         description: 'a body that is not application/json is 415',
         fallback: "Failed — {$evidence}"
      )
         ->expect([$A['type'], $A['form']])
         ->to->be([415, 415])
         ->assert();

      yield new Assertion(
         description: 'only notifications: 202 with no body',
         fallback: "Failed — {$evidence}"
      )
         ->expect($A['notification'])
         ->to->be([202, ''])
         ->assert();

      yield new Assertion(
         description: 'over HTTP/1.0 a POST is answered JSON even when a stream is preferred; stream-only is 406',
         fallback: "Failed — {$evidence}"
      )
         ->expect($A['legacy'] ?? null)
         ->to->be([[200, 'application/json', 2], 406])
         ->assert();

   })
);
