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
use Error;
use ReflectionProperty;
use RuntimeException;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\RPC\JSON;


/**
 * `JSON\Fault` is the designed error: a `RuntimeException` whose code and
 * message reach the client as `error.code` / `error.message`, and whose
 * optional `$data` becomes `error.data` — omitted when null, kept for every
 * other value, falsy ones included. A Fault is not an internal failure: the
 * Reporter never sees it, and a notification that raises one gets no answer.
 */
return new Test(
   description: 'RPC\JSON\Fault: code, message and data reach the client; data omitted when null',
   test: function () {
      // !
      $reports = 0;
      $data = null;
      $JSON = new JSON(
         Methods: [
            'fail' => static function (array $params) use (&$data): never {
               throw new Fault($params['code'], $params['message'], $data);
            },
            'plain' => static function (array $params): never {
               throw new Fault(-32001, 'Plain');
            },
            'params' => static function (array $params): never {
               throw new Fault(JSON::INVALID_PARAMS, 'Invalid params', ['field' => 'name']);
            }
         ],
         Reporter: static function () use (&$reports): void {
            $reports++;
         }
      );
      $request = '{"jsonrpc":"2.0","method":"fail","params":{"code":4001,"message":"Out of stock"},"id":7}';

      // @ The object itself
      $Fault = new Fault(-32050, 'Busy', ['retry' => 5]);
      yield assert(
         assertion: $Fault instanceof RuntimeException,
         description: 'a Fault is a RuntimeException'
      );
      yield assert(
         assertion: $Fault->getCode() === -32050 && $Fault->getMessage() === 'Busy' && $Fault->data === ['retry' => 5],
         description: 'the constructor sets code, message and data'
      );
      yield assert(
         assertion: (new Fault(1, 'x'))->data === null,
         description: 'data defaults to null'
      );

      $written = true;
      try {
         $Fault->data = 'other';
      }
      catch (Error) {
         $written = false;
      }
      yield assert(
         assertion: $written === false && $Fault->data === ['retry' => 5]
            && (new ReflectionProperty(Fault::class, 'data'))->isPrivateSet(),
         description: '`$data` is public private(set): an outside write throws'
      );

      // @ On the wire
      yield assert(
         assertion: $JSON->handle($request)
            === '{"jsonrpc":"2.0","id":7,"error":{"code":4001,"message":"Out of stock"}}',
         description: 'a Fault without data → its code and message, no `data` member'
      );
      yield assert(
         assertion: $JSON->handle('{"jsonrpc":"2.0","method":"plain","id":"p"}')
            === '{"jsonrpc":"2.0","id":"p","error":{"code":-32001,"message":"Plain"}}',
         description: 'a Fault built with no data argument has no `data` member'
      );
      yield assert(
         assertion: $JSON->handle('{"jsonrpc":"2.0","method":"params","id":2}')
            === '{"jsonrpc":"2.0","id":2,"error":{"code":-32602,"message":"Invalid params","data":{"field":"name"}}}',
         description: 'a Fault may carry a standard code, and its data → `error.data`'
      );

      // @@ Falsy data is still data
      foreach ([[0, '0'], [false, 'false'], ['', '""'], [[], '[]'], ['a/é', '"a/é"']] as [$value, $encoded]) {
         $data = $value;

         yield assert(
            assertion: $JSON->handle($request)
               === "{\"jsonrpc\":\"2.0\",\"id\":7,\"error\":{\"code\":4001,\"message\":\"Out of stock\",\"data\":{$encoded}}}",
            description: "data {$encoded} is sent as `error.data` (only null is omitted)"
         );
      }
      $data = null;

      // @ A Fault is not an internal failure
      yield assert(
         assertion: $reports === 0,
         description: 'the Reporter never received a Fault'
      );
      yield assert(
         assertion: $JSON->handle('{"jsonrpc":"2.0","method":"fail","params":{"code":1,"message":"m"}}') === null
            && $reports === 0,
         description: 'a notification that raises a Fault gets no answer, and is not reported'
      );
   }
);
