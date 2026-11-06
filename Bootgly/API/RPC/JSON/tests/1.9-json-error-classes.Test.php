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
use function str_contains;
use Error;
use stdClass;
use Throwable;
use TypeError;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\RPC\JSON;


/**
 * The two guards that keep a failure from escaping `handle()` catch every
 * `Throwable`, `Error`s included — not only `Exception`s: a result whose
 * property hook throws a `TypeError` while it is encoded, and a Reporter that
 * hits an `Error` (array access on an object context) while it logs.
 */
return new Test(
   description: 'JSON: an Error raised while encoding or inside the Reporter never escapes handle()',
   test: function () {
      // ! A result whose property hook throws a TypeError when encoded
      $Hooked = new class {
         public string $secret {
            get => throw new TypeError('hook type: secret-token');
         }
      };
      $runs = [];
      $reported = [];
      $JSON = new JSON(
         [
            'hooked' => static function (array $params) use ($Hooked, &$runs): object {
               $runs[] = 'hooked';
               return $Hooked;
            },
            'crash' => static function (array $params) use (&$runs): never {
               $runs[] = 'crash';
               throw new Error('crash');
            },
            'ok' => static function (array $params) use (&$runs): string {
               $runs[] = 'ok';
               return 'fine';
            }
         ],
         // ! The docs' Reporter shape, given an object context: an Error while it logs
         Reporter: static function (Throwable $Throwable, array $request, mixed $context) use (&$reported): void {
            $reported[] = $Throwable::class;
            $session = $context['session'] ?? '-';
         }
      );

      // @ A TypeError from a property hook while encoding: -32603 for that id, nothing leaks
      $answer = $JSON->handle('{"jsonrpc":"2.0","method":"hooked","id":3}', ['session' => 'S']);

      yield assert(
         assertion: $answer === '{"jsonrpc":"2.0","id":3,"error":{"code":-32603,"message":"Internal error"}}'
            && str_contains((string) $answer, 'secret-token') === false,
         description: "a TypeError raised while encoding is answered -32603 for its id (got {$answer})"
      );
      yield assert(
         assertion: $reported === [TypeError::class],
         description: 'the TypeError reaches the Reporter'
      );

      // @ A Reporter that raises an Error: the batch still answers every entry, each method once
      $runs = [];
      $reported = [];
      $answer = $JSON->handle(
         '[{"jsonrpc":"2.0","method":"crash","id":1},{"jsonrpc":"2.0","method":"ok","id":2}]',
         new stdClass
      );

      yield assert(
         assertion: $answer === '[{"jsonrpc":"2.0","id":1,"error":{"code":-32603,"message":"Internal error"}},{"jsonrpc":"2.0","id":2,"result":"fine"}]',
         description: "a Reporter raising an Error drops it and the batch answers both entries (got {$answer})"
      );
      yield assert(
         assertion: $runs === ['crash', 'ok'] && $reported === [Error::class],
         description: 'each method ran once and the Reporter was called before its own Error'
      );

      // @ The same Reporter while a hooked result is encoded, inside a batch
      $runs = [];
      $answer = $JSON->handle(
         '[{"jsonrpc":"2.0","method":"hooked","id":1},{"jsonrpc":"2.0","method":"ok","id":2}]',
         new stdClass
      );

      yield assert(
         assertion: $answer === '[{"jsonrpc":"2.0","id":1,"error":{"code":-32603,"message":"Internal error"}},{"jsonrpc":"2.0","id":2,"result":"fine"}]',
         description: "an encode failure reported by a Reporter that raises an Error still answers the batch (got {$answer})"
      );
   }
);
