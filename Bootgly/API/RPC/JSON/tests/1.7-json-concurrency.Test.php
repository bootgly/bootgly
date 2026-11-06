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
use Fiber;
use RuntimeException;
use stdClass;
use Throwable;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\RPC\JSON;


/**
 * The dispatcher keeps no per-request state: one instance may serve
 * concurrent requests. Two dispatches interleaved on the same instance — each
 * suspended inside its method by a Fiber — do not see each other's ids,
 * results, failures or contexts: each dispatch carries its own context, its
 * method still sees that context after the other dispatch ran, and the
 * Reporter receives each failure with its own request and its own context, in
 * the order the failures happen.
 */
return new Test(
   description: 'RPC\JSON: no per-request state — interleaved dispatches on one instance stay apart',
   test: function () {
      // !
      $Reports = [];
      $Seen = [];
      $JSON = new JSON(
         Methods: [
            'slow' => static function (array $params, mixed $context) use (&$Seen): string {
               Fiber::suspend($params['tag']);

               // ! The context, read after the other dispatch ran
               $Seen[] = [$params['tag'], $context->tag];

               // ?
               if ($params['fail'] === true) {
                  throw new RuntimeException("failed {$params['tag']}");
               }

               // :
               return "done {$params['tag']}";
            }
         ],
         Reporter: static function (Throwable $Throwable, array $request, mixed $context) use (&$Reports): void {
            $Reports[] = [$Throwable->getMessage(), $request['id'], $request['params']['tag'], $context->tag];
         }
      );
      $dispatch = static fn (string $tag, bool $fail, bool $batch = false): Fiber => new Fiber(
         static function () use ($JSON, $tag, $fail, $batch): null|string {
            $fails = $fail ? 'true' : 'false';
            $request = "{\"jsonrpc\":\"2.0\",\"method\":\"slow\",\"params\":{\"tag\":\"{$tag}\",\"fail\":{$fails}},\"id\":\"{$tag}\"}";
            $Context = new stdClass();
            $Context->tag = "context {$tag}";

            return $JSON->handle($batch ? "[{$request}]" : $request, $Context);
         }
      );
      $internal = static fn (string $id): string
         => "{\"jsonrpc\":\"2.0\",\"id\":\"{$id}\",\"error\":{\"code\":-32603,\"message\":\"Internal error\"}}";

      // @ A fails, B succeeds; B finishes first
      $A = $dispatch('a', fail: true);
      $B = $dispatch('b', fail: false);
      $suspended = [$A->start(), $B->start()];
      yield assert(
         assertion: $suspended === ['a', 'b'] && $A->isSuspended() && $B->isSuspended(),
         description: 'both dispatches are suspended inside the method at once'
      );

      $B->resume();
      $A->resume();
      yield assert(
         assertion: $B->getReturn() === '{"jsonrpc":"2.0","id":"b","result":"done b"}',
         description: 'B gets its own id and result, untouched by A'
      );
      yield assert(
         assertion: $A->getReturn() === $internal('a'),
         description: 'A gets its own Internal error, for its own id'
      );
      yield assert(
         assertion: $Reports === [['failed a', 'a', 'a', 'context a']],
         description: 'the Reporter received only A\'s failure, with A\'s request and A\'s context'
      );
      yield assert(
         assertion: $Seen === [['b', 'context b'], ['a', 'context a']],
         description: 'each method, resumed after the other dispatch ran, still sees only its own context'
      );

      // @ F fails while G is still inside its method; G then succeeds
      $Reports = [];
      $Seen = [];
      $F = $dispatch('f', fail: true);
      $G = $dispatch('g', fail: false);
      $F->start();
      $G->start();
      $F->resume();
      $G->resume();
      yield assert(
         assertion: $F->getReturn() === $internal('f')
            && $G->getReturn() === '{"jsonrpc":"2.0","id":"g","result":"done g"}',
         description: 'a failure that lands while another dispatch is in flight does not leak into it'
      );
      yield assert(
         assertion: $Reports === [['failed f', 'f', 'f', 'context f']],
         description: 'the Reporter received only F\'s failure, with F\'s request and F\'s context'
      );
      yield assert(
         assertion: $Seen === [['f', 'context f'], ['g', 'context g']],
         description: 'F and G each saw only their own context'
      );

      // @ Both fail, in reverse order of start
      $Reports = [];
      $Seen = [];
      $C = $dispatch('c', fail: true);
      $D = $dispatch('d', fail: true, batch: true);
      $C->start();
      $D->start();
      $D->resume();
      $C->resume();
      yield assert(
         assertion: $C->getReturn() === $internal('c') && $D->getReturn() === "[{$internal('d')}]",
         description: 'each failed dispatch is answered for its own id (single and batch)'
      );
      yield assert(
         assertion: $Reports === [['failed d', 'd', 'd', 'context d'], ['failed c', 'c', 'c', 'context c']],
         description: 'the Reporter received each failure with its own request and context, in the order they happened'
      );
      yield assert(
         assertion: $Seen === [['d', 'context d'], ['c', 'context c']],
         description: 'C (single) and D (batch) each saw only their own context'
      );

      // @ A failure left behind does not stick to the next request
      $Reports = [];
      $E = $dispatch('e', fail: false);
      $E->start();
      $E->resume();
      yield assert(
         assertion: $E->getReturn() === '{"jsonrpc":"2.0","id":"e","result":"done e"}' && count($Reports) === 0,
         description: 'a later dispatch on the same instance succeeds cleanly'
      );
   }
);
