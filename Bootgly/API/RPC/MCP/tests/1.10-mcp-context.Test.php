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


use const NAN;
use function assert;
use function count;
use Closure;
use JsonException;
use RuntimeException;
use stdClass;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\RPC\MCP;


/**
 * Whatever belongs to one call travels as `handle($payload, $context)` and
 * reaches every application closure as its LAST argument — the Lister,
 * Caller, Resources, Templates, Reader, Prompts, Prompter and Initializer —
 * and the Reporter, exactly so: no argument added, none dropped. Without a
 * context they receive `null`; every request of a batch receives the batch's.
 */
return new Test(
   description: 'RPC\MCP: the call\'s context reaches every closure as its last argument',
   test: function () {
      // !
      $Context = new stdClass;
      $Context->session = 'S-1';
      $log = [];
      $Recorder = static function (string $closure, mixed $result) use (&$log): Closure {
         return static function (mixed ...$arguments) use ($closure, $result, &$log): mixed {
            $log[] = [$closure, $arguments];

            return $result;
         };
      };
      $Reported = [];
      $MCP = new MCP(
         name: 'Tester',
         version: '1',
         Lister: $Recorder('Lister', [['name' => 'echo']]),
         Caller: static function (mixed ...$arguments) use (&$log): mixed {
            $log[] = ['Caller', $arguments];

            return match ($arguments[0]) {
               'boom' => throw new RuntimeException('hidden'),
               'nan' => ['content' => [['type' => 'text', 'text' => NAN]]],
               default => ['content' => [], 'isError' => false]
            };
         },
         Resources: $Recorder('Resources', [['uri' => 'file:///a', 'name' => 'a']]),
         Templates: $Recorder('Templates', [['uriTemplate' => 'file:///{name}', 'name' => 'file']]),
         Reader: $Recorder('Reader', [['uri' => 'file:///a', 'text' => 'A']]),
         Prompts: $Recorder('Prompts', [['name' => 'greet']]),
         Prompter: $Recorder('Prompter', ['messages' => []]),
         Initializer: $Recorder('Initializer', null),
         Reporter: static function (mixed ...$arguments) use (&$Reported): void {
            $Reported[] = $arguments;
         }
      );
      $initialize = ['protocolVersion' => '2025-06-18', 'clientInfo' => ['name' => 'Client']];

      // @@ Every closure, one call each, with the context
      $payloads = [
         '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","clientInfo":{"name":"Client"}}}',
         '{"jsonrpc":"2.0","id":2,"method":"tools/list"}',
         '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"echo","arguments":{"text":"hi"}}}',
         '{"jsonrpc":"2.0","id":4,"method":"resources/list"}',
         '{"jsonrpc":"2.0","id":5,"method":"resources/templates/list"}',
         '{"jsonrpc":"2.0","id":6,"method":"resources/read","params":{"uri":"file:///a"}}',
         '{"jsonrpc":"2.0","id":7,"method":"prompts/list"}',
         '{"jsonrpc":"2.0","id":8,"method":"prompts/get","params":{"name":"greet","arguments":{"who":"Ana"}}}'
      ];
      $expected = [
         ['Initializer', ['2025-06-18', $initialize, $Context]],
         ['Lister', [$Context]],
         ['Caller', ['echo', ['text' => 'hi'], $Context]],
         ['Resources', [$Context]],
         ['Templates', [$Context]],
         ['Reader', ['file:///a', $Context]],
         ['Prompts', [$Context]],
         ['Prompter', ['greet', ['who' => 'Ana'], $Context]]
      ];
      foreach ($payloads as $index => $payload) {
         $log = [];
         $MCP->handle($payload, $Context);
         $closure = $expected[$index][0];
         yield assert(
            assertion: $log === [$expected[$index]],
            description: "{$closure} receives exactly its arguments, then the very context object"
         );
      }

      // @ The methods that run no closure stay so
      $log = [];
      $MCP->handle('{"jsonrpc":"2.0","id":9,"method":"ping"}', $Context);
      $MCP->handle('{"jsonrpc":"2.0","method":"notifications/initialized"}', $Context);
      $MCP->handle('{"jsonrpc":"2.0","method":"notifications/cancelled","params":{"requestId":3}}', $Context);
      yield assert(
         assertion: $log === [],
         description: 'ping and the notifications run no application closure'
      );

      // @ Any value is a context; none is null
      $log = [];
      $MCP->handle('{"jsonrpc":"2.0","id":10,"method":"tools/list"}', 'S-2');
      $MCP->handle('{"jsonrpc":"2.0","id":11,"method":"tools/list"}', ['session' => 'S-3']);
      $MCP->handle('{"jsonrpc":"2.0","id":12,"method":"tools/list"}');
      yield assert(
         assertion: $log === [['Lister', ['S-2']], ['Lister', [['session' => 'S-3']]], ['Lister', [null]]],
         description: 'a string, an array or no context (null) reaches the Lister as it was given'
      );

      // @ A batch hands its context to each request
      $log = [];
      $MCP->handle('[{"jsonrpc":"2.0","id":1,"method":"tools/list"},{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"echo"}},{"jsonrpc":"2.0","id":3,"method":"prompts/list"}]', $Context);
      yield assert(
         assertion: $log === [['Lister', [$Context]], ['Caller', ['echo', [], $Context]], ['Prompts', [$Context]]],
         description: 'each request of a batch receives the batch\'s context'
      );

      // @ The Reporter receives the context too
      $Reported = [];
      $MCP->handle('{"jsonrpc":"2.0","id":13,"method":"tools/call","params":{"name":"boom"}}', $Context);
      yield assert(
         assertion: count($Reported) === 1
            && count($Reported[0]) === 3
            && $Reported[0][0] instanceof RuntimeException
            && $Reported[0][1] === ['jsonrpc' => '2.0', 'id' => 13, 'method' => 'tools/call', 'params' => ['name' => 'boom']]
            && $Reported[0][2] === $Context,
         description: 'a Caller failure → the Reporter receives the Throwable, the request and the context'
      );
      $Reported = [];
      $answer = $MCP->handle('{"jsonrpc":"2.0","id":14,"method":"tools/call","params":{"name":"nan"}}', $Context);
      yield assert(
         assertion: $answer === '{"jsonrpc":"2.0","id":14,"error":{"code":-32603,"message":"Internal error"}}'
            && count($Reported) === 1
            && count($Reported[0]) === 3
            && $Reported[0][0] instanceof JsonException
            && $Reported[0][1] === ['jsonrpc' => '2.0', 'id' => 14, 'method' => 'tools/call', 'params' => ['name' => 'nan']]
            && $Reported[0][2] === $Context,
         description: 'a result that cannot be encoded → Internal error, and the Reporter receives the context'
      );
      $Reported = [];
      $MCP->handle('{"jsonrpc":"2.0","id":15,"method":"tools/call","params":{"name":"boom"}}');
      yield assert(
         assertion: count($Reported) === 1 && count($Reported[0]) === 3 && $Reported[0][2] === null,
         description: 'without a context the Reporter receives null as its third argument'
      );

      // @ The context is never kept: the next call sees only its own
      $Other = new stdClass;
      $log = [];
      $MCP->handle('{"jsonrpc":"2.0","id":16,"method":"tools/list"}', $Other);
      $MCP->handle('{"jsonrpc":"2.0","id":17,"method":"tools/list"}');
      yield assert(
         assertion: $log === [['Lister', [$Other]], ['Lister', [null]]],
         description: 'a call without a context after one with it receives null, not the previous context'
      );
   }
);
