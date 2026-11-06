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


use function array_map;
use function assert;
use ReflectionClass;
use ReflectionProperty;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\RPC\JSON;
use Bootgly\API\RPC\MCP;


/**
 * The server keeps no per-client state: one instance serves every client of a
 * worker. Two clients initializing different revisions on the same instance
 * each get their own; nothing gates the other methods behind `initialize`;
 * `initialize` is never part of a batch; and the class declares no property
 * where a negotiated revision could live — the revision goes to the
 * Initializer, for the transport's session.
 */
return new Test(
   description: 'RPC\MCP: no per-client state — one instance, many clients, many revisions',
   test: function () {
      // !
      $told = [];
      $MCP = new MCP(
         name: 'Tester',
         version: '1',
         Lister: static fn (): array => [['name' => 'echo']],
         Caller: static fn (string $name, array $arguments): array => [],
         Initializer: static function (string $revision, array $params) use (&$told): void {
            $told[] = [$params['clientInfo']['name'] ?? null, $revision];
         }
      );
      $Class = new ReflectionClass(MCP::class);
      $Dispatcher = new ReflectionClass(JSON::class);
      $Snapshot = static function () use ($Class, $Dispatcher, $MCP): array {
         $values = [];
         foreach ($Class->getProperties() as $Property) {
            $values[$Property->name] = $Property->getValue($MCP);
         }
         foreach ($Dispatcher->getProperties() as $Property) {
            $values["JSON->{$Property->name}"] = $Property->getValue($MCP->JSON);
         }

         return $values;
      };
      $before = $Snapshot();

      // @ Nothing is gated behind initialize
      yield assert(
         assertion: $MCP->handle('{"jsonrpc":"2.0","id":1,"method":"tools/list"}') === '{"jsonrpc":"2.0","id":1,"result":{"tools":[{"name":"echo"}]}}',
         description: 'tools/list answers before any initialize'
      );

      // @ Two clients, two revisions, one instance
      $A = $MCP->handle('{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","clientInfo":{"name":"A"}}}');
      $B = $MCP->handle('{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","clientInfo":{"name":"B"}}}');
      $C = $MCP->handle('{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"clientInfo":{"name":"C"}}}');
      $A2 = $MCP->handle('{"jsonrpc":"2.0","id":2,"method":"initialize","params":{"protocolVersion":"2024-11-05","clientInfo":{"name":"A"}}}');
      yield assert(
         assertion: $A === '{"jsonrpc":"2.0","id":1,"result":{"protocolVersion":"2024-11-05","capabilities":{"tools":{"listChanged":false}},"serverInfo":{"name":"Tester","version":"1"}}}',
         description: 'client A asking for 2024-11-05 gets 2024-11-05'
      );
      yield assert(
         assertion: $B === '{"jsonrpc":"2.0","id":1,"result":{"protocolVersion":"2025-06-18","capabilities":{"tools":{"listChanged":false}},"serverInfo":{"name":"Tester","version":"1"}}}',
         description: 'client B, after A, asking for 2025-06-18 gets 2025-06-18'
      );
      yield assert(
         assertion: $C === '{"jsonrpc":"2.0","id":1,"result":{"protocolVersion":"2025-11-25","capabilities":{"tools":{"listChanged":false}},"serverInfo":{"name":"Tester","version":"1"}}}',
         description: 'client C, asking for nothing after B, gets the newest — not B\'s revision'
      );
      yield assert(
         assertion: $A2 === '{"jsonrpc":"2.0","id":2,"result":{"protocolVersion":"2024-11-05","capabilities":{"tools":{"listChanged":false}},"serverInfo":{"name":"Tester","version":"1"}}}',
         description: 'client A initializing again still gets its own 2024-11-05'
      );
      yield assert(
         assertion: $told === [['A', '2024-11-05'], ['B', '2025-06-18'], ['C', '2025-11-25'], ['A', '2024-11-05']],
         description: 'the Initializer is told each client\'s own revision, in order'
      );

      // @ Not within one batch: initialize inside a batch is refused whole
      yield assert(
         assertion: $MCP->handle('[{"jsonrpc":"2.0","id":"a","method":"initialize","params":{"protocolVersion":"2025-03-26"}},{"jsonrpc":"2.0","id":"b","method":"initialize","params":{"protocolVersion":"2024-11-05"}}]')
            === '{"jsonrpc":"2.0","id":null,"error":{"code":-32600,"message":"Invalid Request"}}',
         description: 'two initialize requests in one batch → one Invalid Request for the whole batch'
      );
      yield assert(
         assertion: $told === [['A', '2024-11-05'], ['B', '2025-06-18'], ['C', '2025-11-25'], ['A', '2024-11-05']],
         description: 'the refused batch told the Initializer nothing'
      );

      // @ No property holds a negotiated revision
      yield assert(
         assertion: array_map(static fn (ReflectionProperty $Property): string => $Property->name, $Class->getProperties())
            === ['name', 'version', 'instructions', 'Caller', 'Resources', 'Reader', 'Prompts', 'Prompter', 'Initializer', 'JSON'],
         description: 'MCP declares exactly its config, its closures and the dispatcher — no revision property'
      );
      yield assert(
         assertion: array_map(static fn (ReflectionProperty $Property): string => $Property->name, $Class->getProperties(ReflectionProperty::IS_PUBLIC))
            === ['name', 'version', 'instructions', 'JSON'],
         description: 'the public surface is name, version, instructions and JSON'
      );
      yield assert(
         assertion: $Class->getProperties(ReflectionProperty::IS_STATIC) === [],
         description: 'no static property either'
      );
      $public = true;
      foreach ($Class->getProperties(ReflectionProperty::IS_PUBLIC) as $Property) {
         $public = $public && $Property->isPrivateSet() && $Property->isReadOnly() === false;
      }
      yield assert(
         assertion: $public,
         description: 'every public property is public private(set)'
      );
      yield assert(
         assertion: $Snapshot() === $before,
         description: 'after every initialize and the refused batch, each property — the dispatcher\'s too — holds the very same value'
      );
   }
);
