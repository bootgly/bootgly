<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


namespace Bootgly\CLI\MCP;


use function assert;
use function fopen;
use function fwrite;
use function ob_start;
use function rewind;
use function str_contains;
use function str_repeat;
use function stream_get_contents;
use function strlen;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\RPC;
use Bootgly\CLI\MCP;


/**
 * Bounds: a line longer than `line` bytes is discarded whole — never parsed
 * in pieces — and answered once with Invalid Request (`id: null`) plus a
 * stderr diagnostic; the next line is served. A line of exactly `line` bytes
 * is served. A closed answer stream ends the loop with status 1. Nested
 * output buffers a tool leaves open are drained to stderr too.
 */
return new Test(
   description: 'CLI\MCP: the line bound, a closed stdout and leaked output buffers',
   test: function () {
      // !
      $Server = new RPC\MCP(
         name: 'Stdio',
         version: '1',
         Lister: static fn (mixed $context): array => [],
         Caller: static function (string $name, array $arguments, mixed $context): array {
            echo 'outer';
            ob_start();
            echo 'inner';

            return ['content' => []];
         }
      );
      $ping = '{"jsonrpc":"2.0","id":1,"method":"ping"}';
      $bound = strlen($ping);
      $limit = 100;
      $Input = fopen('php://memory', 'w+');
      $Output = fopen('php://memory', 'w+');
      $Error = fopen('php://memory', 'w+');
      $long = '{"jsonrpc":"2.0","id":7,"method":"ping","params":{"x":"' . str_repeat('a', 100_000) . '"}}';
      fwrite($Input, "{$long}\n{$ping}\n{\"jsonrpc\":\"2.0\",\"id\":5,\"method\":\"tools/call\",\"params\":{\"name\":\"t\"}}\n");
      rewind($Input);

      // @
      $status = (new MCP($Server, line: $limit, input: $Input, output: $Output, error: $Error))->serving();
      rewind($Output);
      rewind($Error);
      $stdout = (string) stream_get_contents($Output);
      $stderr = (string) stream_get_contents($Error);

      yield assert(
         assertion: $status === 0,
         description: 'the loop survives an oversized line'
      );
      yield assert(
         assertion: $stdout === '{"jsonrpc":"2.0","id":null,"error":{"code":-32600,"message":"Invalid Request: message too large"}}' . "\n"
            . '{"jsonrpc":"2.0","id":1,"result":{}}' . "\n"
            . '{"jsonrpc":"2.0","id":5,"result":{"content":[]}}' . "\n",
         description: 'the oversized line is answered once, then every next line is served: ' . $stdout
      );
      yield assert(
         assertion: str_contains($stderr, 'longer than') && str_contains($stderr, "outerinner\n"),
         description: 'the diagnostic and every buffer the tool left open land on stderr: ' . $stderr
      );

      // @ Exactly the bound
      $Input = fopen('php://memory', 'w+');
      $Output = fopen('php://memory', 'w+');
      fwrite($Input, "{$ping}\n");
      rewind($Input);
      (new MCP($Server, line: $bound, input: $Input, output: $Output, error: $Error))->serving();
      rewind($Output);
      yield assert(
         assertion: stream_get_contents($Output) === '{"jsonrpc":"2.0","id":1,"result":{}}' . "\n",
         description: 'a line of exactly the bound is served'
      );

      // @ A closed answer stream
      $Input = fopen('php://memory', 'w+');
      fwrite($Input, "{$ping}\n{$ping}\n");
      rewind($Input);
      $Closed = fopen('php://memory', 'r');
      $status = (new MCP($Server, input: $Input, output: $Closed, error: $Error))->serving();
      yield assert(
         assertion: $status === 1,
         description: 'an answer stream that cannot be written ends the loop with 1'
      );
   }
);
