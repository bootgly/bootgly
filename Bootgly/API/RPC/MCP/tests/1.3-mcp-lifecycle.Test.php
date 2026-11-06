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

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\RPC\MCP;


/**
 * The lifecycle methods: `notifications/initialized` and
 * `notifications/cancelled` owe nothing (null), `ping` answers `{}`. A method
 * the server does not register is Method not found; invalid JSON is a Parse
 * error — both from the inner JSON-RPC dispatcher.
 */
return new Test(
   description: 'RPC\MCP: notifications/initialized, notifications/cancelled and ping',
   test: function () {
      // !
      $MCP = new MCP(
         name: 'Tester',
         version: '1',
         Lister: static fn (): array => [],
         Caller: static fn (string $name, array $arguments): array => []
      );

      // @ Notifications owe nothing
      yield assert(
         assertion: $MCP->handle('{"jsonrpc":"2.0","method":"notifications/initialized"}') === null,
         description: 'notifications/initialized → null (nothing to send)'
      );
      yield assert(
         assertion: $MCP->handle('{"jsonrpc":"2.0","method":"notifications/initialized","params":{"_meta":{"k":1}}}') === null,
         description: 'notifications/initialized with params → null'
      );
      yield assert(
         assertion: $MCP->handle('{"jsonrpc":"2.0","method":"notifications/cancelled","params":{"requestId":3,"reason":"User aborted"}}') === null,
         description: 'notifications/cancelled → null'
      );
      yield assert(
         assertion: $MCP->handle('{"jsonrpc":"2.0","method":"notifications/cancelled"}') === null,
         description: 'notifications/cancelled without params → null'
      );
      yield assert(
         assertion: $MCP->handle('[{"jsonrpc":"2.0","method":"notifications/initialized"},{"jsonrpc":"2.0","method":"notifications/cancelled","params":{"requestId":1}}]') === null,
         description: 'a batch of both notifications → null'
      );
      yield assert(
         assertion: $MCP->handle('{"jsonrpc":"2.0","method":"notifications/unknown"}') === null,
         description: 'an unregistered notification → null (a notification is never answered)'
      );

      // @ Sent wrongly as a request (with an id), they answer {}
      yield assert(
         assertion: $MCP->handle('{"jsonrpc":"2.0","id":4,"method":"notifications/initialized"}')
            === '{"jsonrpc":"2.0","id":4,"result":{}}',
         description: 'notifications/initialized with an id → result {}'
      );
      yield assert(
         assertion: $MCP->handle('{"jsonrpc":"2.0","id":5,"method":"notifications/cancelled","params":{"requestId":4}}')
            === '{"jsonrpc":"2.0","id":5,"result":{}}',
         description: 'notifications/cancelled with an id → result {}'
      );

      // @ ping → {}
      yield assert(
         assertion: $MCP->handle('{"jsonrpc":"2.0","id":1,"method":"ping"}') === '{"jsonrpc":"2.0","id":1,"result":{}}',
         description: 'ping → result {} (an object, not [] nor null)'
      );
      yield assert(
         assertion: $MCP->handle('{"jsonrpc":"2.0","id":"p-1","method":"ping","params":{}}') === '{"jsonrpc":"2.0","id":"p-1","result":{}}',
         description: 'ping with a string id and empty params → result {} for that id'
      );
      yield assert(
         assertion: $MCP->handle('{"jsonrpc":"2.0","method":"ping"}') === null,
         description: 'ping as a notification → null'
      );

      // @ Anything else comes from the JSON-RPC dispatcher
      yield assert(
         assertion: $MCP->handle('{"jsonrpc":"2.0","id":2,"method":"resources/subscribe","params":{"uri":"x"}}')
            === '{"jsonrpc":"2.0","id":2,"error":{"code":-32601,"message":"Method not found"}}',
         description: 'an unregistered method → -32601 Method not found'
      );
      yield assert(
         assertion: $MCP->handle('{"jsonrpc":"2.0","id":3,"method":"Ping"}')
            === '{"jsonrpc":"2.0","id":3,"error":{"code":-32601,"message":"Method not found"}}',
         description: 'method names are case-sensitive: Ping → -32601'
      );
      yield assert(
         assertion: $MCP->handle('{"jsonrpc":"2.0","id":1,"method":"ping"')
            === '{"jsonrpc":"2.0","id":null,"error":{"code":-32700,"message":"Parse error"}}',
         description: 'invalid JSON → -32700 Parse error'
      );
      yield assert(
         assertion: $MCP->handle('{"jsonrpc":"1.0","id":1,"method":"ping"}')
            === '{"jsonrpc":"2.0","id":1,"error":{"code":-32600,"message":"Invalid Request"}}',
         description: 'a request that is not JSON-RPC 2.0 → -32600 Invalid Request'
      );
   }
);
