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
use function is_float;
use function json_decode;
use function str_contains;
use stdClass;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\RPC\JSON;


/**
 * Request ids, the request shape and params. An id is a string, a number or
 * null, echoed back as it came; a non-finite number (`1e999` decodes to INF),
 * an object, an array or a boolean is refused with Invalid Request and a null
 * id, before the method runs. Ids come back as PHP decoded them: an integer
 * past `PHP_INT_MAX` is a float, answered as a float — not digit for digit. A
 * malformed request echoes its id when that id is valid. A payload is a batch
 * only when its first non-whitespace character is `[`: a JSON object whose
 * keys are "0", "1"… is one request — Invalid Request — and no method runs. Params, when present, are structured — an object or an array —
 * anything else is `-32602 Invalid params`. Results are encoded with slashes
 * and Unicode unescaped; a `stdClass` result is sent as `{}`.
 */
return new Test(
   description: 'RPC\JSON: ids (string, number, null; INF/object/array refused), request shape and params',
   test: function () {
      // !
      $runs = 0;
      $JSON = new JSON([
         'echo' => static function (array $params) use (&$runs): array {
            $runs++;

            return $params;
         },
         'empty' => static fn (array $params): stdClass => new stdClass(),
         'nothing' => static fn (array $params): null => null,
         'text' => static fn (array $params): string => 'a/b é "q"'
      ]);
      $invalid = static fn (string $id): string
         => "{\"jsonrpc\":\"2.0\",\"id\":{$id},\"error\":{\"code\":-32600,\"message\":\"Invalid Request\"}}";
      $params = static fn (string $id): string
         => "{\"jsonrpc\":\"2.0\",\"id\":{$id},\"error\":{\"code\":-32602,\"message\":\"Invalid params\"}}";

      // @@ Valid ids are echoed as they came
      foreach (['"abc"', '""', '7', '0', '-3', '1.5', '-0.25', 'null'] as $id) {
         yield assert(
            assertion: $JSON->handle("{\"jsonrpc\":\"2.0\",\"method\":\"echo\",\"params\":[1],\"id\":{$id}}")
               === "{\"jsonrpc\":\"2.0\",\"id\":{$id},\"result\":[1]}",
            description: "id {$id} is echoed back with the result"
         );
      }
      yield assert(
         assertion: $JSON->handle('{"jsonrpc":"2.0","method":"echo","id":null}') !== null,
         description: 'an explicit null id is a call, not a notification: it is answered'
      );

      // @@ Invalid ids: refused before the method runs
      $runs = 0;
      foreach (['1e999', '-1e999', '{"a":1}', '[1]', '[]', 'true', 'false'] as $id) {
         yield assert(
            assertion: $JSON->handle("{\"jsonrpc\":\"2.0\",\"method\":\"echo\",\"id\":{$id}}") === $invalid('null'),
            description: "id {$id} → Invalid Request, id null"
         );
      }
      yield assert(
         assertion: $runs === 0,
         description: 'no method ran for an invalid id'
      );

      // @@ Malformed requests: Invalid Request, echoing a valid id
      $cases = [
         '{"jsonrpc":"2.0","id":5}' => $invalid('5'),
         '{"jsonrpc":"1.0","method":"echo","id":"a"}' => $invalid('"a"'),
         '{"jsonrpc":2.0,"method":"echo","id":"n"}' => $invalid('"n"'),
         '{"method":"echo","id":3}' => $invalid('3'),
         '{"jsonrpc":"2.0","method":["echo"],"id":4}' => $invalid('4'),
         '{"jsonrpc":"2.0","method":null,"id":6}' => $invalid('6'),
         '{"jsonrpc":"2.0","id":1e999}' => $invalid('null'),
         '{"jsonrpc":"2.0","id":[1]}' => $invalid('null'),
         '{}' => $invalid('null'),
         '5' => $invalid('null'),
         '"x"' => $invalid('null'),
         'true' => $invalid('null'),
         'null' => $invalid('null'),
         '[[1,2]]' => "[{$invalid('null')}]",
         '[{}]' => "[{$invalid('null')}]"
      ];
      foreach ($cases as $payload => $expected) {
         yield assert(
            assertion: $JSON->handle($payload) === $expected,
            description: "{$payload} → Invalid Request"
         );
      }

      // @ Batch detection: the first non-whitespace character
      $runs = 0;
      $call = '{"jsonrpc":"2.0","method":"echo","params":[1],"id":1}';
      yield assert(
         assertion: $JSON->handle("{\"0\":{$call},\"1\":{$call}}") === $invalid('null'),
         description: 'an object with keys "0", "1" is one request, not a batch → a single Invalid Request'
      );
      yield assert(
         assertion: $JSON->handle("\n {\"0\":{$call}}") === $invalid('null'),
         description: 'an object with key "0" after leading whitespace is still one request'
      );
      yield assert(
         assertion: $runs === 0,
         description: 'no method ran for an object keyed like a list'
      );
      yield assert(
         assertion: $JSON->handle(" \t\r\n[{$call},\n {$call}]\n")
            === '[{"jsonrpc":"2.0","id":1,"result":[1]},{"jsonrpc":"2.0","id":1,"result":[1]}]'
            && $runs === 2,
         description: 'a batch after leading spaces, tabs, CRs and LFs is still a batch: both entries run'
      );

      // @ Ids past PHP_INT_MAX come back as PHP decoded them: a float
      yield assert(
         assertion: $JSON->handle('{"jsonrpc":"2.0","method":"echo","id":9223372036854775807}')
            === '{"jsonrpc":"2.0","id":9223372036854775807,"result":[]}',
         description: 'PHP_INT_MAX is an int id: echoed digit for digit'
      );
      $answer = (string) $JSON->handle('{"jsonrpc":"2.0","method":"echo","id":12345678901234567890}');
      $decoded = json_decode($answer, true);
      yield assert(
         assertion: is_float($decoded['id']) && $decoded['result'] === [] && str_contains($answer, '12345678901234567890') === false,
         description: 'id 12345678901234567890 decodes to a float: answered (not refused) with a float id, not digit for digit'
      );

      // @@ Not JSON at all
      foreach (['', '   ', '{', '{"id":"\ud800"}', "\"\xB1\""] as $payload) {
         yield assert(
            assertion: $JSON->handle($payload)
               === '{"jsonrpc":"2.0","id":null,"error":{"code":-32700,"message":"Parse error"}}',
            description: 'a payload that does not decode → Parse error'
         );
      }

      // @@ Params not structured: -32602 for its id, nothing for a notification
      $runs = 0;
      foreach (['"bar"', '5', '1.5', 'true', 'false', 'null'] as $value) {
         yield assert(
            assertion: $JSON->handle("{\"jsonrpc\":\"2.0\",\"method\":\"echo\",\"params\":{$value},\"id\":9}") === $params('9'),
            description: "params {$value} → -32602 Invalid params for its id"
         );
         yield assert(
            assertion: $JSON->handle("{\"jsonrpc\":\"2.0\",\"method\":\"echo\",\"params\":{$value}}") === null,
            description: "a notification with params {$value} answers nothing"
         );
      }
      yield assert(
         assertion: $runs === 0,
         description: 'no method ran with unstructured params'
      );
      yield assert(
         assertion: $JSON->handle('{"jsonrpc":"2.0","method":"missing","params":"bar","id":9}') === $params('9'),
         description: 'params are checked before the method lookup'
      );

      // @ Structured params arrive decoded
      yield assert(
         assertion: $JSON->execute(['jsonrpc' => '2.0', 'method' => 'echo', 'id' => 1])['result'] === [],
         description: 'omitted params → the method receives []'
      );
      yield assert(
         assertion: $JSON->handle('{"jsonrpc":"2.0","method":"echo","params":{"a":{"b":[1,2]}},"id":1}')
            === '{"jsonrpc":"2.0","id":1,"result":{"a":{"b":[1,2]}}}',
         description: 'object params arrive as an associative array, nested'
      );
      yield assert(
         assertion: $JSON->handle('{"jsonrpc":"2.0","method":"echo","params":[],"id":1}')
            === '{"jsonrpc":"2.0","id":1,"result":[]}',
         description: 'empty array params are structured'
      );
      yield assert(
         assertion: $JSON->handle('{"jsonrpc":"2.0","method":"echo","params":{},"id":1}')
            === '{"jsonrpc":"2.0","id":1,"result":[]}',
         description: 'empty object params are structured (decoded to [])'
      );

      // @ Results
      yield assert(
         assertion: $JSON->handle('{"jsonrpc":"2.0","method":"empty","id":1}') === '{"jsonrpc":"2.0","id":1,"result":{}}',
         description: 'a stdClass result is sent as {}'
      );
      yield assert(
         assertion: $JSON->handle('{"jsonrpc":"2.0","method":"nothing","id":1}') === '{"jsonrpc":"2.0","id":1,"result":null}',
         description: 'a null result keeps the `result` member'
      );
      yield assert(
         assertion: $JSON->handle('{"jsonrpc":"2.0","method":"text","id":1}') === '{"jsonrpc":"2.0","id":1,"result":"a/b é \"q\""}',
         description: 'slashes and Unicode are sent unescaped'
      );

      // @ Method names are exact
      yield assert(
         assertion: $JSON->handle('{"jsonrpc":"2.0","method":"Echo","id":1}')
            === '{"jsonrpc":"2.0","id":1,"error":{"code":-32601,"message":"Method not found"}}',
         description: 'method lookup is case-sensitive'
      );
   }
);
