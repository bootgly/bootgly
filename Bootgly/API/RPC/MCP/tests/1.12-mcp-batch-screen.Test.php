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
use function chr;
use function count;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\RPC\MCP;


/**
 * `MCP::handle()` refuses a batch that carries `initialize` before the
 * dispatcher sees it, and decodes a batch for that check only when it can name
 * `initialize` — literally or through a `\u` escape. Its decision never
 * disagrees with the dispatcher's own decode: an invalid batch still gets the
 * Parse error, and an entry without `jsonrpc` still counts.
 */
return new Test(
   description: 'MCP: the initialize-in-batch screen — escapes, invalid JSON, entries without jsonrpc',
   test: function () {
      // !
      $initialized = 0;
      $MCP = new MCP(
         'srv', '1.0',
         static fn (mixed $context): array => [],
         static fn (string $name, array $arguments, mixed $context): array => ['content' => [], 'isError' => false],
         Initializer: static function (string $revision, array $params, mixed $context) use (&$initialized): void {
            $initialized++;
         }
      );
      $refused = '{"jsonrpc":"2.0","id":null,"error":{"code":-32600,"message":"Invalid Request"}}';
      // ! A backslash, so the escape below reaches the payload as written
      $slash = chr(92);

      // @@ Batches the screen refuses whole
      $batches = [
         'an escaped method name' => "[{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"{$slash}u0069nitialize\"}]",
         'an entry without jsonrpc' => '[{"id":1,"method":"initialize"}]',
         'initialize after a ping' => '[{"jsonrpc":"2.0","id":1,"method":"ping"},{"jsonrpc":"2.0","id":2,"method":"initialize"}]'
      ];
      foreach ($batches as $case => $payload) {
         $answer = $MCP->handle($payload);

         yield assert(
            assertion: $answer === $refused,
            description: "{$case}: the whole batch is refused (got {$answer})"
         );
      }

      // @ Invalid JSON naming initialize: the dispatcher's Parse error, not the refusal
      $answer = $MCP->handle("[{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"initialize\",\"x\":\"\xB1\"}]");

      yield assert(
         assertion: $answer === '{"jsonrpc":"2.0","id":null,"error":{"code":-32700,"message":"Parse error"}}',
         description: "an invalid-UTF-8 batch naming initialize gets the Parse error (got {$answer})"
      );

      // @ A batch that cannot name initialize is answered entry by entry
      $answer = (string) $MCP->handle('[{"jsonrpc":"2.0","id":1,"method":"ping"},{"jsonrpc":"2.0","id":2,"method":"tools/list"}]');

      yield assert(
         assertion: $answer === '[{"jsonrpc":"2.0","id":1,"result":{}},{"jsonrpc":"2.0","id":2,"result":{"tools":[]}}]',
         description: "a batch without initialize is answered (got {$answer})"
      );

      // @ The text "initialize" outside a method name does not refuse the batch
      $answer = (string) $MCP->handle('[{"jsonrpc":"2.0","id":1,"method":"ping","params":{"note":"initialize later"}}]');

      yield assert(
         assertion: $answer === '[{"jsonrpc":"2.0","id":1,"result":{}}]',
         description: "a batch that only mentions initialize in a value is answered (got {$answer})"
      );

      yield assert(
         assertion: $initialized === 0 && count($batches) === 3,
         description: 'no refused batch ever reached the Initializer'
      );
   }
);
