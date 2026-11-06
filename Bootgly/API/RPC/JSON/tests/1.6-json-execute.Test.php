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


use function assert;
use function count;
use RuntimeException;
use stdClass;
use Throwable;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\RPC\JSON;


/**
 * `execute()` runs one request object already decoded (as `json_decode()`
 * with `associative: true` returns it) and returns the response object as a
 * PHP array — nothing is encoded, so it never fails on encoding — or null for
 * a notification. It is what `handle()` runs per request; a transport that
 * decodes on its own (or an MCP layer) calls it directly. Its optional
 * second argument is the call's context, handed to the method as its second
 * argument and to the Reporter as its third; without it, both receive null.
 */
return new Test(
   description: 'RPC\JSON::execute(): one decoded request in, the response array (or null) out',
   test: function () {
      // !
      $Reports = [];
      $JSON = new JSON(
         Methods: [
            'subtract' => static fn (array $params): int => $params[0] - $params[1],
            'crash' => static function (array $params): never {
               throw new RuntimeException('boom');
            },
            'fault' => static function (array $params): never {
               throw new Fault(-32010, 'Nope', ['why' => 'x']);
            },
            'binary' => static fn (array $params): string => "\xB1",
            'context' => static fn (array $params, mixed $context): array => ['context' => $context],
            'blame' => static function (array $params, mixed $context): never {
               throw new RuntimeException('blame');
            }
         ],
         Reporter: static function (Throwable $Throwable, array $request, mixed $context) use (&$Reports): void {
            $Reports[] = [$Throwable, $request, $context];
         }
      );
      $call = static fn (string $method, mixed $id = 1): array
         => ['jsonrpc' => '2.0', 'method' => $method, 'params' => [42, 23], 'id' => $id];

      // @ Success
      yield assert(
         assertion: $JSON->execute($call('subtract')) === ['jsonrpc' => '2.0', 'id' => 1, 'result' => 19],
         description: 'a call → the response array: jsonrpc, id, result'
      );
      yield assert(
         assertion: $JSON->execute(['jsonrpc' => '2.0', 'method' => 'subtract', 'params' => [5, 1]]) === null,
         description: 'a notification → null'
      );

      // @ Errors as arrays
      yield assert(
         assertion: $JSON->execute($call('missing', 'm'))
            === ['jsonrpc' => '2.0', 'id' => 'm', 'error' => ['code' => JSON::METHOD_NOT_FOUND, 'message' => 'Method not found']],
         description: 'a missing method → the error array, for its id'
      );
      yield assert(
         assertion: $JSON->execute($call('fault'))
            === ['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code' => -32010, 'message' => 'Nope', 'data' => ['why' => 'x']]],
         description: 'a Fault → its code, message and data'
      );
      yield assert(
         assertion: $JSON->execute($call('crash'))
               === ['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code' => JSON::INTERNAL_ERROR, 'message' => 'Internal error']]
            && count($Reports) === 1 && $Reports[0][1] === $call('crash'),
         description: 'an unexpected Throwable → Internal error, reported with the request'
      );
      yield assert(
         assertion: $JSON->execute(['jsonrpc' => '2.0', 'method' => 'subtract', 'params' => 'x', 'id' => 2])
            === ['jsonrpc' => '2.0', 'id' => 2, 'error' => ['code' => JSON::INVALID_PARAMS, 'message' => 'Invalid params']],
         description: 'unstructured params → Invalid params'
      );

      // @@ Not a request object
      $refused = ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => JSON::INVALID_REQUEST, 'message' => 'Invalid Request']];
      foreach ([null, 1, 'text', true, [], [1, 2], ['jsonrpc' => '2.0'], ['jsonrpc' => '2.0', 'method' => 'subtract', 'id' => 1.5e400]] as $request) {
         yield assert(
            assertion: $JSON->execute($request) === $refused,
            description: 'a value that is not a valid request object → Invalid Request, id null'
         );
      }
      yield assert(
         assertion: $JSON->execute(['jsonrpc' => '2.0', 'id' => 'kept'])['id'] === 'kept',
         description: 'a malformed request keeps its valid id'
      );
      yield assert(
         assertion: $JSON->execute(['jsonrpc' => '2.0', 'method' => 'subtract', 'params' => [1, 1], 'id' => new RuntimeException('id')]) === $refused,
         description: 'an object id → Invalid Request, id null'
      );

      // @ Nothing is encoded: execute() never fails on encoding
      $Reports = [];
      yield assert(
         assertion: $JSON->execute($call('binary')) === ['jsonrpc' => '2.0', 'id' => 1, 'result' => "\xB1"]
            && $Reports === [],
         description: 'an un-encodable result is returned raw and not reported — encoding is handle()\'s job'
      );

      // @ The call's context
      $Context = new stdClass();
      yield assert(
         assertion: $JSON->execute($call('context'), $Context) === ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['context' => $Context]]
            && $JSON->execute($call('context'), $Context)['result']['context'] === $Context,
         description: 'execute($request, $context) hands the very context to the method, as its second argument'
      );
      yield assert(
         assertion: $JSON->execute($call('context')) === ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['context' => null]],
         description: 'without a context the method receives null'
      );
      $Reports = [];
      $JSON->execute($call('blame'), 'session-x');
      $JSON->execute(['jsonrpc' => '2.0', 'method' => 'blame']);
      yield assert(
         assertion: count($Reports) === 2 && $Reports[0][2] === 'session-x' && $Reports[1][2] === null,
         description: 'the Reporter receives the context as its third argument (null without one)'
      );

      // @ The error codes
      yield assert(
         assertion: [JSON::PARSE_ERROR, JSON::INVALID_REQUEST, JSON::METHOD_NOT_FOUND, JSON::INVALID_PARAMS, JSON::INTERNAL_ERROR]
            === [-32700, -32600, -32601, -32602, -32603],
         description: 'the standard error codes are -32700, -32600, -32601, -32602, -32603'
      );
   }
);
