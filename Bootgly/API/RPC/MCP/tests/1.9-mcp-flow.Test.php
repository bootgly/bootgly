<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\RPC\MCP;


use function assert;
use function implode;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\RPC\MCP;


/**
 * A client's whole session — initialize, notifications/initialized,
 * tools/list, tools/call — sent as separate payloads (Streamable HTTP, stdio):
 * the notification owes nothing. `initialize` is never part of a batch: a
 * batch carrying it, wherever it sits, is refused whole with one Invalid
 * Request and nothing in it runs; the rest of the session as a batch is
 * answered, in the requests' order.
 */
return new Test(
   description: 'RPC\MCP: initialize → tools/list → tools/call; a batch never carries initialize',
   test: function () {
      // !
      $revisions = [];
      $calls = [];
      $listed = 0;
      $MCP = new MCP(
         name: 'Echo',
         version: '2.0.0',
         Lister: static function () use (&$listed): array {
            $listed++;

            return [
               ['name' => 'echo', 'description' => 'Echo', 'inputSchema' => ['type' => 'object']]
            ];
         },
         Caller: static function (string $name, array $arguments) use (&$calls): array {
            $calls[] = [$name, $arguments];

            return [
               'content' => [['type' => 'text', 'text' => "{$name}: {$arguments['text']}"]],
               'isError' => false
            ];
         },
         instructions: 'Call echo with a text.',
         Initializer: static function (string $revision, array $params) use (&$revisions): void {
            $revisions[] = $revision;
         }
      );
      $requests = [
         '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"Client","version":"1"}}}',
         '{"jsonrpc":"2.0","method":"notifications/initialized"}',
         '{"jsonrpc":"2.0","id":2,"method":"tools/list"}',
         '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"echo","arguments":{"text":"hello"}}}'
      ];
      $answers = [
         '{"jsonrpc":"2.0","id":1,"result":{"protocolVersion":"2025-06-18","capabilities":{"tools":{"listChanged":false}},"serverInfo":{"name":"Echo","version":"2.0.0"},"instructions":"Call echo with a text."}}',
         null,
         '{"jsonrpc":"2.0","id":2,"result":{"tools":[{"name":"echo","description":"Echo","inputSchema":{"type":"object"}}]}}',
         '{"jsonrpc":"2.0","id":3,"result":{"content":[{"type":"text","text":"echo: hello"}],"isError":false}}'
      ];

      // @@ Separate payloads
      foreach ($requests as $index => $request) {
         yield assert(
            assertion: $MCP->handle($request) === $answers[$index],
            description: "separate payload {$index}: answered as expected"
         );
      }
      yield assert(
         assertion: $revisions === ['2025-06-18'],
         description: 'the Initializer was told the agreed revision once'
      );

      // @ One batch carrying initialize: refused whole, nothing runs
      $refused = '{"jsonrpc":"2.0","id":null,"error":{"code":-32600,"message":"Invalid Request"}}';
      $revisions = [];
      $calls = [];
      $listed = 0;
      $batch = implode(',', $requests);
      yield assert(
         assertion: $MCP->handle("[{$batch}]") === $refused,
         description: 'the whole session as one batch → exactly one Invalid Request, id null'
      );
      $batch = implode(',', [$requests[2], $requests[3], $requests[0]]);
      yield assert(
         assertion: $MCP->handle(" \t\r\n[{$batch}]") === $refused,
         description: 'initialize last in the batch, after whitespace → refused whole all the same'
      );
      yield assert(
         assertion: $MCP->handle("[{$requests[1]},{$requests[0]}]") === $refused,
         description: 'initialize after a notification → refused whole all the same'
      );
      yield assert(
         assertion: $MCP->handle("[{$requests[0]}]") === $refused,
         description: 'a batch of initialize alone → refused whole'
      );
      yield assert(
         assertion: $MCP->handle('[{"jsonrpc":"2.0","method":"initialize","params":{"protocolVersion":"2025-06-18"}}]') === $refused,
         description: 'initialize sent as a notification inside a batch → refused whole too'
      );
      yield assert(
         assertion: $revisions === [] && $calls === [] && $listed === 0,
         description: 'no refused batch ran a closure: no Initializer, no Lister, no Caller'
      );

      // @ One batch without initialize: answered in order
      $batch = implode(',', [$requests[1], $requests[2], $requests[3]]);
      yield assert(
         assertion: $MCP->handle("[{$batch}]") === "[{$answers[2]},{$answers[3]}]",
         description: 'notifications/initialized, tools/list and tools/call as a batch: two answers in order, none for the notification'
      );
      yield assert(
         assertion: $listed === 1 && $calls === [['echo', ['text' => 'hello']]] && $revisions === [],
         description: 'that batch ran the Lister and the Caller once each'
      );
      yield assert(
         assertion: $MCP->handle('[{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"initialize","arguments":{"text":"x"}}}]')
            === '[{"jsonrpc":"2.0","id":1,"result":{"content":[{"type":"text","text":"initialize: x"}],"isError":false}}]',
         description: 'a tool named "initialize" is not the initialize method: its batch is answered'
      );
      yield assert(
         assertion: $MCP->handle('[{"jsonrpc":"2.0","id":1,"method":"initialize"') === '{"jsonrpc":"2.0","id":null,"error":{"code":-32700,"message":"Parse error"}}',
         description: 'invalid JSON starting with [ and naming initialize → still the Parse error'
      );
      yield assert(
         assertion: $MCP->handle('{"0":{"jsonrpc":"2.0","id":1,"method":"initialize"}}') === '{"jsonrpc":"2.0","id":null,"error":{"code":-32600,"message":"Invalid Request"}}'
            && $revisions === [],
         description: 'an object keyed "0" is one request, not a batch: Invalid Request, no Initializer'
      );
      yield assert(
         assertion: $MCP->handle('{"0":{"jsonrpc":"2.0","id":1,"method":"initialize"},"jsonrpc":"2.0","id":5,"method":"ping"}') === '{"jsonrpc":"2.0","id":5,"result":{}}'
            && $revisions === [],
         description: 'an object carrying an initialize under "0" is still one request: here a ping, answered'
      );

      // @ A batch mixing answers and errors keeps going
      yield assert(
         assertion: $MCP->handle('[{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":""}},{"jsonrpc":"2.0","id":2,"method":"ping"},{"jsonrpc":"2.0","id":3,"method":"resources/list"}]')
            === '[{"jsonrpc":"2.0","id":1,"error":{"code":-32602,"message":"Invalid params: name (string) and arguments (object) are required"}},{"jsonrpc":"2.0","id":2,"result":{}},{"jsonrpc":"2.0","id":3,"error":{"code":-32601,"message":"Method not found"}}]',
         description: 'an invalid call and a missing method do not stop the rest of the batch'
      );
   }
);
