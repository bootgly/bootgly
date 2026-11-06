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


use function array_fill;
use function assert;
use function count;
use function implode;
use function json_decode;
use Error;
use InvalidArgumentException;
use ReflectionProperty;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\RPC\JSON;


/**
 * The batch cap: `$batch` (default 100) is the most requests one batch may
 * carry. A batch exactly at the cap runs; one request past it is refused as a
 * whole — one Invalid Request object with a null id, not an array — before any
 * method runs. `0` refuses every batch (single requests still run), and a
 * negative cap is a construction error. The cap is readable, never writable
 * from outside.
 */
return new Test(
   description: 'RPC\JSON: the batch cap — at the limit runs, past it is refused whole before any method runs',
   test: function () {
      // !
      $runs = 0;
      $Methods = [
         'ping' => static function (array $params) use (&$runs): string {
            $runs++;

            return 'pong';
         }
      ];
      $refused = '{"jsonrpc":"2.0","id":null,"error":{"code":-32600,"message":"Invalid Request"}}';
      $call = '{"jsonrpc":"2.0","method":"ping","id":1}';
      $notify = '{"jsonrpc":"2.0","method":"ping"}';
      $batch = static function (string $request, int $count): string {
         $list = implode(',', array_fill(0, $count, $request));

         return "[{$list}]";
      };

      // @ Default cap: 100
      $JSON = new JSON($Methods);
      yield assert(
         assertion: $JSON->batch === 100,
         description: 'the cap defaults to 100'
      );

      $answer = $JSON->handle($batch($call, 100));
      $decoded = json_decode((string) $answer, associative: true);
      yield assert(
         assertion: $runs === 100 && count($decoded) === 100 && $decoded[99]['result'] === 'pong',
         description: 'a batch of exactly 100 runs all 100 requests and answers each'
      );

      $runs = 0;
      yield assert(
         assertion: $JSON->handle($batch($call, 101)) === $refused,
         description: 'a batch of 101 is refused whole: one Invalid Request object, id null, not an array'
      );
      yield assert(
         assertion: $runs === 0,
         description: 'no method of the refused batch ran'
      );

      // @ Custom cap
      $JSON = new JSON($Methods, batch: 3);
      yield assert(
         assertion: $JSON->batch === 3,
         description: 'a custom cap is kept'
      );

      $runs = 0;
      yield assert(
         assertion: $JSON->handle($batch($notify, 3)) === null && $runs === 3,
         description: 'a batch of notifications exactly at the cap runs every one'
      );

      $runs = 0;
      yield assert(
         assertion: $JSON->handle($batch($notify, 4)) === $refused && $runs === 0,
         description: 'a batch of notifications one past the cap is refused (an answer is owed) and none ran'
      );

      $runs = 0;
      yield assert(
         assertion: $JSON->handle('[1,2,3,4]') === $refused,
         description: 'the cap is checked before the shape of each entry: 4 invalid entries → one refusal'
      );

      // @ Cap 0: every batch refused, single requests still served
      $JSON = new JSON($Methods, batch: 0);

      $runs = 0;
      yield assert(
         assertion: $JSON->handle($batch($call, 1)) === $refused && $runs === 0,
         description: 'cap 0 refuses even a batch of one, before it runs'
      );
      yield assert(
         assertion: $JSON->handle($call) === '{"jsonrpc":"2.0","id":1,"result":"pong"}' && $runs === 1,
         description: 'cap 0 still serves a single request object'
      );
      yield assert(
         assertion: $JSON->handle('[]') === $refused,
         description: 'an empty array stays an Invalid Request object under cap 0'
      );

      // @ Negative cap: a construction error
      $message = null;
      try {
         new JSON($Methods, batch: -1);
      }
      catch (InvalidArgumentException $Exception) {
         $message = $Exception->getMessage();
      }
      yield assert(
         assertion: $message === 'The batch limit cannot be negative (-1).',
         description: 'a negative cap throws InvalidArgumentException naming the value'
      );

      // @ The cap is not writable from outside
      $JSON = new JSON($Methods, batch: 2);
      $written = true;
      try {
         $JSON->batch = 1000;
      }
      catch (Error) {
         $written = false;
      }
      yield assert(
         assertion: $written === false && $JSON->batch === 2
            && (new ReflectionProperty(JSON::class, 'batch'))->isPrivateSet(),
         description: '`$batch` is public private(set): an outside write throws and the cap stays'
      );
   }
);
