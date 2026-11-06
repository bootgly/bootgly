<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\RPC\JSON;


use function array_sum;
use function assert;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\RPC\JSON;


/**
 * Every example of section 7 of the JSON-RPC 2.0 specification, payload in,
 * exact payload out: positional and named params, notifications, a missing
 * method, invalid JSON, an invalid Request object, a batch with invalid JSON,
 * an empty array, non-empty invalid batches, a mixed batch and a batch of
 * notifications only (nothing owed: null).
 */
return new Test(
   description: 'RPC\JSON: the JSON-RPC 2.0 specification examples (section 7)',
   test: function () {
      // !
      $updates = [];
      $hellos = [];
      $JSON = new JSON([
         'subtract' => static fn (array $params): int => isset($params['minuend'])
            ? $params['minuend'] - $params['subtrahend']
            : $params[0] - $params[1],
         'sum' => static fn (array $params): int => array_sum($params),
         'update' => static function (array $params) use (&$updates): void {
            $updates[] = $params;
         },
         'notify_hello' => static function (array $params) use (&$hellos): void {
            $hellos[] = $params;
         },
         'notify_sum' => static fn (array $params): int => array_sum($params),
         'get_data' => static fn (array $params): array => ['hello', 5]
      ]);

      // @ RPC call with positional parameters
      yield assert(
         assertion: $JSON->handle('{"jsonrpc": "2.0", "method": "subtract", "params": [42, 23], "id": 1}')
            === '{"jsonrpc":"2.0","id":1,"result":19}',
         description: 'positional params [42, 23] → result 19, id 1'
      );
      yield assert(
         assertion: $JSON->handle('{"jsonrpc": "2.0", "method": "subtract", "params": [23, 42], "id": 2}')
            === '{"jsonrpc":"2.0","id":2,"result":-19}',
         description: 'positional params [23, 42] → result -19, id 2'
      );

      // @ RPC call with named parameters
      yield assert(
         assertion: $JSON->handle('{"jsonrpc": "2.0", "method": "subtract", "params": {"subtrahend": 23, "minuend": 42}, "id": 3}')
            === '{"jsonrpc":"2.0","id":3,"result":19}',
         description: 'named params {subtrahend: 23, minuend: 42} → result 19, id 3'
      );
      yield assert(
         assertion: $JSON->handle('{"jsonrpc": "2.0", "method": "subtract", "params": {"minuend": 42, "subtrahend": 23}, "id": 4}')
            === '{"jsonrpc":"2.0","id":4,"result":19}',
         description: 'named params in another order → the same result 19, id 4'
      );

      // @ Notifications: nothing owed, but the method runs
      yield assert(
         assertion: $JSON->handle('{"jsonrpc": "2.0", "method": "update", "params": [1,2,3,4,5]}') === null,
         description: 'a notification returns null'
      );
      yield assert(
         assertion: $updates === [[1, 2, 3, 4, 5]],
         description: 'the notified method ran once, with its params'
      );
      yield assert(
         assertion: $JSON->handle('{"jsonrpc": "2.0", "method": "foobar"}') === null,
         description: 'a notification of a method that does not exist returns null too'
      );

      // @ RPC call of non-existent method
      yield assert(
         assertion: $JSON->handle('{"jsonrpc": "2.0", "method": "foobar", "id": "1"}')
            === '{"jsonrpc":"2.0","id":"1","error":{"code":-32601,"message":"Method not found"}}',
         description: 'a non-existent method → -32601 Method not found, for its string id "1"'
      );

      // @ RPC call with invalid JSON
      yield assert(
         assertion: $JSON->handle('{"jsonrpc": "2.0", "method": "foobar, "params": "bar", "baz]')
            === '{"jsonrpc":"2.0","id":null,"error":{"code":-32700,"message":"Parse error"}}',
         description: 'invalid JSON → -32700 Parse error, id null'
      );

      // @ RPC call with invalid Request object
      yield assert(
         assertion: $JSON->handle('{"jsonrpc": "2.0", "method": 1, "params": "bar"}')
            === '{"jsonrpc":"2.0","id":null,"error":{"code":-32600,"message":"Invalid Request"}}',
         description: 'a numeric method → -32600 Invalid Request, id null'
      );

      // @ RPC call Batch, invalid JSON
      yield assert(
         assertion: $JSON->handle(<<<'JSON'
         [
           {"jsonrpc": "2.0", "method": "sum", "params": [1,2,4], "id": "1"},
           {"jsonrpc": "2.0", "method"
         ]
         JSON) === '{"jsonrpc":"2.0","id":null,"error":{"code":-32700,"message":"Parse error"}}',
         description: 'a batch with invalid JSON → one Parse error object, not an array'
      );

      // @ RPC call with an empty Array
      yield assert(
         assertion: $JSON->handle('[]')
            === '{"jsonrpc":"2.0","id":null,"error":{"code":-32600,"message":"Invalid Request"}}',
         description: 'an empty array → one Invalid Request object, not an array'
      );

      // @ RPC call with an invalid Batch (but not empty)
      yield assert(
         assertion: $JSON->handle('[1]')
            === '[{"jsonrpc":"2.0","id":null,"error":{"code":-32600,"message":"Invalid Request"}}]',
         description: '[1] → an array of one Invalid Request'
      );

      // @ RPC call with invalid Batch
      $invalid = '{"jsonrpc":"2.0","id":null,"error":{"code":-32600,"message":"Invalid Request"}}';
      yield assert(
         assertion: $JSON->handle('[1,2,3]') === "[{$invalid},{$invalid},{$invalid}]",
         description: '[1,2,3] → an array of three Invalid Request'
      );

      // @ RPC call Batch
      $hellos = [];
      $sum = '{"jsonrpc":"2.0","id":"1","result":7}';
      $subtract = '{"jsonrpc":"2.0","id":"2","result":19}';
      $missing = '{"jsonrpc":"2.0","id":"5","error":{"code":-32601,"message":"Method not found"}}';
      $data = '{"jsonrpc":"2.0","id":"9","result":["hello",5]}';
      yield assert(
         assertion: $JSON->handle(<<<'JSON'
         [
           {"jsonrpc": "2.0", "method": "sum", "params": [1,2,4], "id": "1"},
           {"jsonrpc": "2.0", "method": "notify_hello", "params": [7]},
           {"jsonrpc": "2.0", "method": "subtract", "params": [42,23], "id": "2"},
           {"foo": "boo"},
           {"jsonrpc": "2.0", "method": "foo.get", "params": {"name": "myself"}, "id": "5"},
           {"jsonrpc": "2.0", "method": "get_data", "id": "9"}
         ]
         JSON) === "[{$sum},{$subtract},{$invalid},{$missing},{$data}]",
         description: 'a mixed batch → five responses in request order, the notification skipped'
      );
      yield assert(
         assertion: $hellos === [[7]],
         description: 'the notification inside the batch still ran'
      );

      // @ RPC call Batch (all notifications)
      $hellos = [];
      yield assert(
         assertion: $JSON->handle(<<<'JSON'
         [
           {"jsonrpc": "2.0", "method": "notify_sum", "params": [1,2,4]},
           {"jsonrpc": "2.0", "method": "notify_hello", "params": [7]}
         ]
         JSON) === null,
         description: 'a batch of notifications only returns null (nothing owed)'
      );
      yield assert(
         assertion: $hellos === [[7]],
         description: 'the notifications of that batch ran'
      );
   }
);
