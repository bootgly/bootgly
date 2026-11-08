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
use function rewind;
use function stream_get_contents;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\RPC;
use Bootgly\CLI\MCP;


/**
 * Framing over in-memory streams: blank lines are skipped, CRLF line ends
 * and a last line without a newline are served, notifications get no
 * answer line, a batch is answered on one line, a JSON-RPC response (the
 * server core sends no requests) is refused by the core as Invalid Request,
 * and malformed JSON gets a Parse error line (the loop goes on).
 */
return new Test(
   description: 'CLI\MCP: line framing on stdin and stdout',
   test: function () {
      // !
      $Server = new RPC\MCP(
         name: 'Stdio',
         version: '1',
         Lister: static fn (mixed $context): array => [],
         Caller: static fn (string $name, array $arguments, mixed $context): array => ['content' => []]
      );
      $Serve = static function (string $input) use ($Server): array {
         $Input = fopen('php://memory', 'w+');
         $Output = fopen('php://memory', 'w+');
         $Error = fopen('php://memory', 'w+');
         fwrite($Input, $input);
         rewind($Input);
         $status = (new MCP($Server, input: $Input, output: $Output, error: $Error))->serving();
         rewind($Output);
         rewind($Error);

         return [$status, (string) stream_get_contents($Output), (string) stream_get_contents($Error)];
      };

      // @
      [$status, $stdout] = $Serve(
         "\n   \n"
         . "{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"ping\"}\r\n"
         . "{\"jsonrpc\":\"2.0\",\"method\":\"notifications/initialized\"}\n"
         . "{\"jsonrpc\":\"2.0\",\"id\":9,\"result\":{}}\n"
         . "[{\"jsonrpc\":\"2.0\",\"id\":2,\"method\":\"ping\"},{\"jsonrpc\":\"2.0\",\"id\":3,\"method\":\"ping\"}]\n"
         . "{not json\n"
         . "{\"jsonrpc\":\"2.0\",\"id\":4,\"method\":\"ping\"}"
      );
      yield assert(
         assertion: $status === 0,
         description: 'end of input returns 0'
      );
      yield assert(
         assertion: $stdout === '{"jsonrpc":"2.0","id":1,"result":{}}' . "\n"
            . '{"jsonrpc":"2.0","id":9,"error":{"code":-32600,"message":"Invalid Request"}}' . "\n"
            . '[{"jsonrpc":"2.0","id":2,"result":{}},{"jsonrpc":"2.0","id":3,"result":{}}]' . "\n"
            . '{"jsonrpc":"2.0","id":null,"error":{"code":-32700,"message":"Parse error"}}' . "\n"
            . '{"jsonrpc":"2.0","id":4,"result":{}}' . "\n",
         description: 'one answer line per request, batch or response (refused by the core), none for blank lines or notifications: ' . $stdout
      );

      // @ Empty input
      [$status, $stdout] = $Serve('');
      yield assert(
         assertion: $status === 0 && $stdout === '',
         description: 'an empty input ends at once, writing nothing'
      );
   }
);
