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
 * The per-call context: `handle($payload, $context)` hands the same value to
 * every method it runs — as the second argument — and to the Reporter — as
 * the third — for a method failure and for an un-encodable result alike, in
 * a single request and in every entry of a batch. Without one the context is
 * null. Nothing is stored on the dispatcher: the next call sees only its own.
 */
return new Test(
   description: 'RPC\JSON: handle() passes the call\'s context to every method (2nd arg) and to the Reporter (3rd); default null',
   test: function () {
      // !
      $Calls = [];
      $Reports = [];
      $JSON = new JSON(
         Methods: [
            'whoami' => static function (array $params, mixed $context) use (&$Calls): string {
               $Calls[] = [$params['tag'] ?? null, $context];

               return 'ok';
            },
            'crash' => static function (array $params, mixed $context) use (&$Calls): never {
               $Calls[] = [$params['tag'] ?? null, $context];

               throw new RuntimeException('boom');
            },
            'binary' => static function (array $params, mixed $context) use (&$Calls): string {
               $Calls[] = [$params['tag'] ?? null, $context];

               return "\xB1";
            }
         ],
         Reporter: static function (Throwable $Throwable, array $request, mixed $context) use (&$Reports): void {
            $Reports[] = [$request['method'], $context];
         }
      );
      $Session = new stdClass();
      $Session->user = 'alice';

      // @ Default: no context → null
      $answer = $JSON->handle('{"jsonrpc":"2.0","method":"whoami","id":1}');
      yield assert(
         assertion: $answer === '{"jsonrpc":"2.0","id":1,"result":"ok"}' && $Calls === [[null, null]],
         description: 'without a context the method receives null as its second argument'
      );

      $Calls = [];
      $JSON->handle('{"jsonrpc":"2.0","method":"crash","id":1}');
      yield assert(
         assertion: $Reports === [['crash', null]],
         description: 'without a context the Reporter receives null as its third argument'
      );

      // @ A single request
      $Calls = [];
      $Reports = [];
      $JSON->handle('{"jsonrpc":"2.0","method":"whoami","params":{"tag":"s"},"id":1}', $Session);
      yield assert(
         assertion: count($Calls) === 1 && $Calls[0][0] === 's' && $Calls[0][1] === $Session,
         description: 'the method receives the very context handle() was given'
      );

      $Calls = [];
      $JSON->handle('{"jsonrpc":"2.0","method":"crash","params":{"tag":"c"}}', 'session-b');
      yield assert(
         assertion: $Calls === [['c', 'session-b']] && $Reports === [['crash', 'session-b']],
         description: 'a failing notification: the method and the Reporter both receive the context'
      );

      $Calls = [];
      $Reports = [];
      $answer = $JSON->handle('{"jsonrpc":"2.0","method":"binary","id":7}', $Session);
      yield assert(
         assertion: $answer === '{"jsonrpc":"2.0","id":7,"error":{"code":-32603,"message":"Internal error"}}'
            && count($Reports) === 1 && $Reports[0][0] === 'binary' && $Reports[0][1] === $Session,
         description: 'an un-encodable result is reported with the call\'s context'
      );

      // @ A batch: every entry receives the same context
      $Calls = [];
      $Reports = [];
      $answer = $JSON->handle(<<<'JSON'
      [
        {"jsonrpc":"2.0","method":"whoami","params":{"tag":"1"},"id":1},
        {"jsonrpc":"2.0","method":"crash","params":{"tag":"2"},"id":2},
        {"jsonrpc":"2.0","method":"whoami","params":{"tag":"3"}},
        {"jsonrpc":"2.0","method":"binary","params":{"tag":"4"},"id":4}
      ]
      JSON, $Session);
      yield assert(
         assertion: $Calls === [['1', $Session], ['2', $Session], ['3', $Session], ['4', $Session]],
         description: 'in a batch every method, notifications included, receives the context'
      );
      yield assert(
         assertion: $Reports === [['crash', $Session], ['binary', $Session]],
         description: 'in a batch the Reporter receives the context for a method failure and an encoding failure'
      );
      yield assert(
         assertion: $answer !== null && count($Calls) === 4,
         description: 'the batch is answered'
      );

      // @ Nothing sticks: the next call sees only its own context
      $Calls = [];
      $Reports = [];
      $JSON->handle('{"jsonrpc":"2.0","method":"whoami","id":1}');
      $JSON->handle('{"jsonrpc":"2.0","method":"crash","id":2}');
      yield assert(
         assertion: $Calls === [[null, null], [null, null]] && $Reports === [['crash', null]],
         description: 'a later call without a context does not inherit the previous one'
      );
   }
);
