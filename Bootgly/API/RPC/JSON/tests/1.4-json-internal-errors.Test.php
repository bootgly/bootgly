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
use function str_contains;
use JsonException;
use JsonSerializable;
use LogicException;
use RuntimeException;
use Throwable;
use TypeError;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\RPC\JSON;


/**
 * Internal failures: any `Throwable` other than a Fault becomes `-32603
 * Internal error` without its message, and the optional Reporter receives the
 * Throwable itself and the request object it came from. A notification that
 * fails owes no answer but is still reported. A response that cannot be
 * JSON-encoded (invalid UTF-8) is reported too and replaced by an Internal
 * error for the same id — inside a batch, its siblings stay intact. So is a
 * result whose `jsonSerialize()` or property get hook throws while it is
 * encoded: the exception reaches the Reporter, never the client. A Reporter
 * that throws is ignored: the response — every entry of a batch — still goes
 * out, and no method runs twice.
 */
return new Test(
   description: 'RPC\JSON: internal errors never leak, reach the Reporter with their request; un-encodable results → -32603',
   test: function () {
      // !
      $Reports = [];
      $Methods = [
         'crash' => static function (array $params): never {
            throw new RuntimeException('secret: password=hunter2');
         },
         'type' => static fn (array $params): int => $params['n'] + [],
         'binary' => static fn (array $params): string => "\xB1\x31",
         'echo' => static fn (array $params): array => $params,
         'fault' => static function (array $params): never {
            throw new Fault(4000, 'Bad', "\xB1");
         },
         'serialize' => static fn (array $params): JsonSerializable => new class implements JsonSerializable {
            public function jsonSerialize (): mixed
            {
               throw new RuntimeException('secret: token=s3rialize');
            }
         },
         'hook' => static fn (array $params): object => new class {
            public int $visible = 1;
            public string $hidden {
               get => throw new LogicException('secret: token=h00k');
            }
         }
      ];
      $JSON = new JSON(
         Methods: $Methods,
         Reporter: static function (Throwable $Throwable, array $request) use (&$Reports): void {
            $Reports[] = [$Throwable, $request];
         }
      );
      $internal = static fn (string $id): string
         => "{\"jsonrpc\":\"2.0\",\"id\":{$id},\"error\":{\"code\":-32603,\"message\":\"Internal error\"}}";

      // @ A method's unexpected Throwable
      $answer = $JSON->handle('{"jsonrpc":"2.0","method":"crash","params":{"a":1},"id":1}');
      yield assert(
         assertion: $answer === $internal('1'),
         description: 'an unexpected exception → -32603 Internal error for its id'
      );
      yield assert(
         assertion: str_contains((string) $answer, 'secret') === false && str_contains((string) $answer, 'hunter2') === false,
         description: 'the exception message never reaches the client'
      );
      yield assert(
         assertion: count($Reports) === 1
            && $Reports[0][0] instanceof RuntimeException
            && $Reports[0][0]->getMessage() === 'secret: password=hunter2',
         description: 'the Reporter receives the Throwable itself'
      );
      yield assert(
         assertion: $Reports[0][1] === ['jsonrpc' => '2.0', 'method' => 'crash', 'params' => ['a' => 1], 'id' => 1],
         description: 'the Reporter receives the decoded request object it came from'
      );

      $Reports = [];
      yield assert(
         assertion: $JSON->handle('{"jsonrpc":"2.0","method":"type","params":{"n":1},"id":"t"}') === $internal('"t"')
            && count($Reports) === 1 && $Reports[0][0] instanceof TypeError,
         description: 'an Error (not only an Exception) is an internal error too, and reported'
      );

      // @ Notifications: no answer, still reported
      $Reports = [];
      yield assert(
         assertion: $JSON->handle('{"jsonrpc":"2.0","method":"crash","params":[9]}') === null,
         description: 'a failing notification gets no answer'
      );
      yield assert(
         assertion: count($Reports) === 1
            && $Reports[0][0] instanceof RuntimeException
            && $Reports[0][1] === ['jsonrpc' => '2.0', 'method' => 'crash', 'params' => [9]],
         description: 'a failing notification still reaches the Reporter, with its request'
      );

      $Reports = [];
      yield assert(
         assertion: $JSON->handle('[{"jsonrpc":"2.0","method":"crash"},{"jsonrpc":"2.0","method":"crash","params":[2]}]') === null
            && count($Reports) === 2 && $Reports[1][1]['params'] === [2],
         description: 'a batch of failing notifications answers nothing, and each failure is reported'
      );

      $Reports = [];
      yield assert(
         assertion: $JSON->handle('{"jsonrpc":"2.0","method":"nope"}') === null
            && $JSON->handle('{"jsonrpc":"2.0","method":"nope","id":1}') !== null
            && $Reports === [],
         description: 'a missing method is not an internal failure: never reported'
      );

      // @ An un-encodable result
      $Reports = [];
      $request = '{"jsonrpc":"2.0","method":"binary","id":5}';
      yield assert(
         assertion: $JSON->handle($request) === $internal('5'),
         description: 'a result with invalid UTF-8 → -32603 Internal error for the same id'
      );
      yield assert(
         assertion: count($Reports) === 1
            && $Reports[0][0] instanceof JsonException
            && $Reports[0][1] === ['jsonrpc' => '2.0', 'method' => 'binary', 'id' => 5],
         description: 'the encoding failure is reported, with the request'
      );

      $Reports = [];
      $answer = $JSON->handle(<<<'JSON'
      [
        {"jsonrpc":"2.0","method":"echo","params":["a"],"id":"before"},
        {"jsonrpc":"2.0","method":"binary","id":"bad"},
        {"jsonrpc":"2.0","method":"echo","params":["c"],"id":"after"}
      ]
      JSON);
      $before = '{"jsonrpc":"2.0","id":"before","result":["a"]}';
      $bad = $internal('"bad"');
      $after = '{"jsonrpc":"2.0","id":"after","result":["c"]}';
      yield assert(
         assertion: $answer === "[{$before},{$bad},{$after}]",
         description: 'inside a batch, only the un-encodable entry becomes -32603; its siblings stay intact'
      );
      yield assert(
         assertion: count($Reports) === 1 && $Reports[0][1]['id'] === 'bad',
         description: 'the Reporter receives the request of the un-encodable entry, not the batch'
      );

      $Reports = [];
      yield assert(
         assertion: $JSON->handle('{"jsonrpc":"2.0","method":"fault","id":"f"}') === $internal('"f"')
            && count($Reports) === 1 && $Reports[0][0] instanceof JsonException,
         description: 'a Fault whose data cannot be encoded → -32603 for its id, reported'
      );

      // @ A result that throws while it is encoded
      foreach (['serialize' => [RuntimeException::class, 's3rialize'], 'hook' => [LogicException::class, 'h00k']] as $method => [$class, $secret]) {
         $Reports = [];
         $answer = $JSON->handle("{\"jsonrpc\":\"2.0\",\"method\":\"{$method}\",\"id\":\"{$method}\"}");
         yield assert(
            assertion: $answer === $internal("\"{$method}\""),
            description: "a result whose {$method} throws while encoding → -32603 for its id"
         );
         yield assert(
            assertion: str_contains((string) $answer, 'secret') === false && str_contains((string) $answer, $secret) === false,
            description: "the {$method} exception message never reaches the client"
         );
         yield assert(
            assertion: count($Reports) === 1
               && $Reports[0][0] instanceof $class
               && $Reports[0][0]->getMessage() === "secret: token={$secret}"
               && $Reports[0][1] === ['jsonrpc' => '2.0', 'method' => $method, 'id' => $method],
            description: "the Reporter receives the {$method} exception itself, with the request"
         );
      }

      $Reports = [];
      $answer = $JSON->handle(<<<'JSON'
      [
        {"jsonrpc":"2.0","method":"echo","params":["a"],"id":"before"},
        {"jsonrpc":"2.0","method":"serialize","id":"s"},
        {"jsonrpc":"2.0","method":"hook","id":"h"},
        {"jsonrpc":"2.0","method":"echo","params":["c"],"id":"after"}
      ]
      JSON);
      yield assert(
         assertion: $answer === "[{$before},{$internal('"s"')},{$internal('"h"')},{$after}]"
            && str_contains((string) $answer, 'secret') === false,
         description: 'inside a batch, only the entries that throw while encoding become -32603; their siblings stay intact'
      );
      yield assert(
         assertion: count($Reports) === 2 && $Reports[0][1]['id'] === 's' && $Reports[1][1]['id'] === 'h',
         description: 'each encoding exception of the batch is reported with its own request'
      );

      // @ A Reporter that throws is ignored
      $runs = [];
      $Throwing = new JSON(
         Methods: [
            'ok' => static function (array $params) use (&$runs): string {
               $runs[] = "ok {$params[0]}";

               return 'fine';
            },
            'crash' => static function (array $params) use (&$runs): never {
               $runs[] = 'crash';

               throw new RuntimeException('secret: method');
            },
            'binary' => static function (array $params) use (&$runs): string {
               $runs[] = 'binary';

               return "\xB1";
            }
         ],
         Reporter: static function (Throwable $Throwable, array $request): never {
            throw new RuntimeException('secret: reporter');
         }
      );
      $ok = static fn (string $id): string => "{\"jsonrpc\":\"2.0\",\"id\":\"{$id}\",\"result\":\"fine\"}";
      $answer = $Throwing->handle(<<<'JSON'
      [
        {"jsonrpc":"2.0","method":"ok","params":[1],"id":"1"},
        {"jsonrpc":"2.0","method":"crash","id":"2"},
        {"jsonrpc":"2.0","method":"ok","params":[3],"id":"3"}
      ]
      JSON);
      yield assert(
         assertion: $answer === "[{$ok('1')},{$internal('"2"')},{$ok('3')}]"
            && str_contains((string) $answer, 'secret') === false,
         description: 'a throwing Reporter: the batch [ok, crash, ok] still answers all three, nothing leaks'
      );
      yield assert(
         assertion: $runs === ['ok 1', 'crash', 'ok 3'],
         description: 'a throwing Reporter: every method of the batch ran exactly once'
      );

      $runs = [];
      $answer = $Throwing->handle(<<<'JSON'
      [
        {"jsonrpc":"2.0","method":"ok","params":[1],"id":"1"},
        {"jsonrpc":"2.0","method":"binary","id":"2"},
        {"jsonrpc":"2.0","method":"ok","params":[3],"id":"3"}
      ]
      JSON);
      yield assert(
         assertion: $answer === "[{$ok('1')},{$internal('"2"')},{$ok('3')}]" && $runs === ['ok 1', 'binary', 'ok 3'],
         description: 'a Reporter that throws on an encoding failure: the batch is still answered whole, each method once'
      );

      $runs = [];
      yield assert(
         assertion: $Throwing->handle('{"jsonrpc":"2.0","method":"crash","id":9}') === $internal('9')
            && $Throwing->handle('{"jsonrpc":"2.0","method":"crash"}') === null
            && $runs === ['crash', 'crash'],
         description: 'a throwing Reporter: a single call is still answered, a notification still answers nothing'
      );

      // @ The Reporter is optional
      $Silent = new JSON($Methods);
      yield assert(
         assertion: $Silent->handle('{"jsonrpc":"2.0","method":"crash","id":1}') === $internal('1'),
         description: 'without a Reporter an internal error is still answered with -32603'
      );
      yield assert(
         assertion: $Silent->handle('{"jsonrpc":"2.0","method":"crash"}') === null,
         description: 'without a Reporter a failing notification still answers nothing'
      );
      yield assert(
         assertion: $Silent->handle('{"jsonrpc":"2.0","method":"binary","id":2}') === $internal('2'),
         description: 'without a Reporter an un-encodable result still becomes -32603'
      );
   }
);
