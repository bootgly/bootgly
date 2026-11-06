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
use function count;
use RuntimeException;
use stdClass;
use Throwable;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\RPC\JSON\Fault;
use Bootgly\API\RPC\MCP;


/**
 * `tools/list` answers `{"tools": …}` from the Lister; `tools/call` validates
 * `name` (a non-empty string) and `arguments` (absent or structured) before
 * the Caller runs — anything else is -32602 —, then sends the Caller's result
 * as it is, `{}` when it is `[]` or `null`.
 */
return new Test(
   description: 'RPC\MCP: tools/list and tools/call',
   test: function () {
      // !
      $tools = [
         [
            'name' => 'echo',
            'description' => 'Echo the text back',
            'inputSchema' => ['type' => 'object', 'properties' => ['text' => ['type' => 'string']], 'required' => ['text']]
         ]
      ];
      $calls = [];
      $listed = 0;
      $MCP = new MCP(
         name: 'Tester',
         version: '1',
         Lister: static function () use ($tools, &$listed): array {
            $listed++;

            return $tools;
         },
         Caller: static function (string $name, array $arguments) use (&$calls): mixed {
            $calls[] = [$name, $arguments];

            return match ($name) {
               'echo' => ['content' => [['type' => 'text', 'text' => $arguments['text'] ?? '']], 'isError' => false],
               'fail' => ['content' => [['type' => 'text', 'text' => 'disk full']], 'isError' => true],
               'empty' => [],
               'nothing' => null,
               'zero' => 0,
               'false' => false,
               'blank' => '',
               'list' => [['type' => 'text', 'text' => 'a'], 'b'],
               'object' => new stdClass,
               'fault' => throw new Fault(-32001, 'Tool quota exceeded', ['retry' => 30]),
               default => throw new RuntimeException("secret detail of {$name}")
            };
         }
      );
      $Invalid = static fn (int $id): string => "{\"jsonrpc\":\"2.0\",\"id\":{$id},\"error\":{\"code\":-32602,\"message\":\"Invalid params: name (string) and arguments (object) are required\"}}";

      // @ tools/list
      yield assert(
         assertion: $MCP->handle('{"jsonrpc":"2.0","id":1,"method":"tools/list"}')
            === '{"jsonrpc":"2.0","id":1,"result":{"tools":[{"name":"echo","description":"Echo the text back","inputSchema":{"type":"object","properties":{"text":{"type":"string"}},"required":["text"]}}]}}',
         description: 'tools/list → {"tools": the Lister\'s tools}'
      );
      yield assert(
         assertion: $listed === 1,
         description: 'the Lister runs once per tools/list'
      );
      yield assert(
         assertion: $MCP->handle('{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{"cursor":"abc"}}')
            === '{"jsonrpc":"2.0","id":2,"result":{"tools":[{"name":"echo","description":"Echo the text back","inputSchema":{"type":"object","properties":{"text":{"type":"string"}},"required":["text"]}}]}}'
            && $listed === 2,
         description: 'tools/list ignores a cursor param and runs the Lister again'
      );
      $Empty = new MCP(
         name: 'Tester',
         version: '1',
         Lister: static fn (): array => [],
         Caller: static fn (string $name, array $arguments): array => []
      );
      yield assert(
         assertion: $Empty->handle('{"jsonrpc":"2.0","id":3,"method":"tools/list"}') === '{"jsonrpc":"2.0","id":3,"result":{"tools":[]}}',
         description: 'no tools → {"tools": []}'
      );

      // @ tools/call — valid
      yield assert(
         assertion: $MCP->handle('{"jsonrpc":"2.0","id":10,"method":"tools/call","params":{"name":"echo","arguments":{"text":"hi"}}}')
            === '{"jsonrpc":"2.0","id":10,"result":{"content":[{"type":"text","text":"hi"}],"isError":false}}',
         description: 'tools/call → the Caller\'s result, as it is'
      );
      yield assert(
         assertion: $calls === [['echo', ['text' => 'hi']]],
         description: 'the Caller receives the name and the decoded arguments'
      );
      $calls = [];
      yield assert(
         assertion: $MCP->handle('{"jsonrpc":"2.0","id":11,"method":"tools/call","params":{"name":"echo"}}')
            === '{"jsonrpc":"2.0","id":11,"result":{"content":[{"type":"text","text":""}],"isError":false}}'
            && $calls === [['echo', []]],
         description: 'absent arguments → the Caller receives []'
      );
      $calls = [];
      $MCP->handle('{"jsonrpc":"2.0","id":12,"method":"tools/call","params":{"name":"echo","arguments":{}}}');
      yield assert(
         assertion: $calls === [['echo', []]],
         description: 'empty arguments {} → the Caller receives []'
      );
      $calls = [];
      $MCP->handle('{"jsonrpc":"2.0","id":13,"method":"tools/call","params":{"name":"echo","arguments":null}}');
      yield assert(
         assertion: $calls === [['echo', []]],
         description: 'null arguments count as absent → the Caller receives []'
      );
      yield assert(
         assertion: $MCP->handle('{"jsonrpc":"2.0","id":14,"method":"tools/call","params":{"name":"fail","arguments":{}}}')
            === '{"jsonrpc":"2.0","id":14,"result":{"content":[{"type":"text","text":"disk full"}],"isError":true}}',
         description: 'a tool failure is a result with isError: true, not a protocol error'
      );

      // @ An empty result is {}
      yield assert(
         assertion: $MCP->handle('{"jsonrpc":"2.0","id":15,"method":"tools/call","params":{"name":"empty"}}')
            === '{"jsonrpc":"2.0","id":15,"result":{}}',
         description: 'a Caller returning [] → result {}'
      );
      yield assert(
         assertion: $MCP->handle('{"jsonrpc":"2.0","id":16,"method":"tools/call","params":{"name":"nothing"}}')
            === '{"jsonrpc":"2.0","id":16,"result":{}}',
         description: 'a Caller returning null → result {}'
      );

      // @ Any other result is sent as it is
      yield assert(
         assertion: $MCP->handle('{"jsonrpc":"2.0","id":30,"method":"tools/call","params":{"name":"zero"}}')
            === '{"jsonrpc":"2.0","id":30,"result":0}',
         description: 'a Caller returning 0 → result 0, not {}'
      );
      yield assert(
         assertion: $MCP->handle('{"jsonrpc":"2.0","id":31,"method":"tools/call","params":{"name":"false"}}')
            === '{"jsonrpc":"2.0","id":31,"result":false}',
         description: 'a Caller returning false → result false, not {}'
      );
      yield assert(
         assertion: $MCP->handle('{"jsonrpc":"2.0","id":32,"method":"tools/call","params":{"name":"blank"}}')
            === '{"jsonrpc":"2.0","id":32,"result":""}',
         description: 'a Caller returning \'\' → result "", not {}'
      );
      yield assert(
         assertion: $MCP->handle('{"jsonrpc":"2.0","id":33,"method":"tools/call","params":{"name":"list"}}')
            === '{"jsonrpc":"2.0","id":33,"result":[{"type":"text","text":"a"},"b"]}',
         description: 'a Caller returning a list → that JSON array, not {}'
      );
      yield assert(
         assertion: $MCP->handle('{"jsonrpc":"2.0","id":34,"method":"tools/call","params":{"name":"object"}}')
            === '{"jsonrpc":"2.0","id":34,"result":{}}',
         description: 'a Caller returning an empty stdClass → result {}, as it is'
      );

      // @ Caller failures
      yield assert(
         assertion: $MCP->handle('{"jsonrpc":"2.0","id":17,"method":"tools/call","params":{"name":"fault"}}')
            === '{"jsonrpc":"2.0","id":17,"error":{"code":-32001,"message":"Tool quota exceeded","data":{"retry":30}}}',
         description: 'a Fault from the Caller reaches the client with its code, message and data'
      );
      yield assert(
         assertion: $MCP->handle('{"jsonrpc":"2.0","id":18,"method":"tools/call","params":{"name":"boom"}}')
            === '{"jsonrpc":"2.0","id":18,"error":{"code":-32603,"message":"Internal error"}}',
         description: 'any other Throwable → -32603 Internal error, its message withheld'
      );

      // @@ tools/call — invalid name or arguments → -32602, the Caller never runs
      $calls = [];
      $cases = [
         'no params' => '{"jsonrpc":"2.0","id":20,"method":"tools/call"}',
         'empty params' => '{"jsonrpc":"2.0","id":20,"method":"tools/call","params":{}}',
         'missing name' => '{"jsonrpc":"2.0","id":20,"method":"tools/call","params":{"arguments":{}}}',
         'empty name' => '{"jsonrpc":"2.0","id":20,"method":"tools/call","params":{"name":""}}',
         'null name' => '{"jsonrpc":"2.0","id":20,"method":"tools/call","params":{"name":null}}',
         'integer name' => '{"jsonrpc":"2.0","id":20,"method":"tools/call","params":{"name":5}}',
         'boolean name' => '{"jsonrpc":"2.0","id":20,"method":"tools/call","params":{"name":true}}',
         'array name' => '{"jsonrpc":"2.0","id":20,"method":"tools/call","params":{"name":["echo"]}}',
         'object name' => '{"jsonrpc":"2.0","id":20,"method":"tools/call","params":{"name":{"n":"echo"}}}',
         'string arguments' => '{"jsonrpc":"2.0","id":20,"method":"tools/call","params":{"name":"echo","arguments":"text=hi"}}',
         'integer arguments' => '{"jsonrpc":"2.0","id":20,"method":"tools/call","params":{"name":"echo","arguments":1}}',
         'boolean arguments' => '{"jsonrpc":"2.0","id":20,"method":"tools/call","params":{"name":"echo","arguments":false}}',
         'positional params' => '{"jsonrpc":"2.0","id":20,"method":"tools/call","params":["echo",{"text":"hi"}]}'
      ];
      foreach ($cases as $case => $payload) {
         yield assert(
            assertion: $MCP->handle($payload) === $Invalid(20),
            description: "tools/call with {$case} → -32602 Invalid params"
         );
      }
      yield assert(
         assertion: $calls === [],
         description: 'no invalid tools/call reached the Caller'
      );
      yield assert(
         assertion: $MCP->handle('{"jsonrpc":"2.0","id":21,"method":"tools/call","params":"echo"}')
            === '{"jsonrpc":"2.0","id":21,"error":{"code":-32602,"message":"Invalid params"}}',
         description: 'unstructured params → the dispatcher\'s own -32602'
      );

      // @ The Caller's Throwable is reported, the Fault and the invalid params are not
      $Reported = [];
      $Reporting = new MCP(
         name: 'Tester',
         version: '1',
         Lister: static fn (): array => [],
         Caller: static fn (string $name, array $arguments): array => $name === 'fault'
            ? throw new Fault(-32001, 'Designed')
            : throw new RuntimeException('hidden'),
         Reporter: static function (Throwable $Throwable, array $request) use (&$Reported): void {
            $Reported[] = [$Throwable, $request];
         }
      );
      $Reporting->handle('{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"fault"}}');
      $Reporting->handle('{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":""}}');
      $Reporting->handle('{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"boom","arguments":{"a":1}}}');
      yield assert(
         assertion: count($Reported) === 1
            && $Reported[0][0] instanceof RuntimeException
            && $Reported[0][0]->getMessage() === 'hidden'
            && $Reported[0][1] === ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'boom', 'arguments' => ['a' => 1]]],
         description: 'only the unexpected Throwable reaches the Reporter, with its request'
      );
   }
);
