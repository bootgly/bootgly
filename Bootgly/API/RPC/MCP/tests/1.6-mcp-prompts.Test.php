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

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\RPC\JSON;
use Bootgly\API\RPC\JSON\Fault;
use Bootgly\API\RPC\MCP;


/**
 * `prompts/list` and `prompts/get` exist only when both `Prompts` and
 * `Prompter` are given — otherwise they are Method not found. A get needs a
 * non-empty string `name` and structured (or absent) `arguments`; the
 * Prompter's Fault for an unknown prompt reaches the client, and a Prompter
 * returning `[]` answers `{}`.
 */
return new Test(
   description: 'RPC\MCP: prompts/list and prompts/get',
   test: function () {
      // !
      $Lister = static fn (): array => [];
      $Caller = static fn (string $name, array $arguments): array => [];
      $Prompts = static fn (): array => [
         ['name' => 'review', 'description' => 'Review code', 'arguments' => [['name' => 'code', 'required' => true]]]
      ];
      $gets = [];
      $Prompter = static function (string $name, array $arguments) use (&$gets): mixed {
         $gets[] = [$name, $arguments];

         return match ($name) {
            'review' => [
               'description' => 'Review code',
               'messages' => [['role' => 'user', 'content' => ['type' => 'text', 'text' => "Review: {$arguments['code']}"]]]
            ],
            'empty' => [],
            'nothing' => null,
            default => throw new Fault(JSON::INVALID_PARAMS, 'Unknown prompt', ['name' => $name])
         };
      };
      $NotFound = static fn (int $id): string => "{\"jsonrpc\":\"2.0\",\"id\":{$id},\"error\":{\"code\":-32601,\"message\":\"Method not found\"}}";

      // @@ Not registered unless both Prompts and Prompter are given
      $Servers = [
         'neither' => new MCP(name: 'Tester', version: '1', Lister: $Lister, Caller: $Caller),
         'only Prompts' => new MCP(name: 'Tester', version: '1', Lister: $Lister, Caller: $Caller, Prompts: $Prompts),
         'only Prompter' => new MCP(name: 'Tester', version: '1', Lister: $Lister, Caller: $Caller, Prompter: $Prompter)
      ];
      foreach ($Servers as $case => $Server) {
         yield assert(
            assertion: $Server->handle('{"jsonrpc":"2.0","id":1,"method":"prompts/list"}') === $NotFound(1),
            description: "{$case}: prompts/list → -32601 Method not found"
         );
         yield assert(
            assertion: $Server->handle('{"jsonrpc":"2.0","id":2,"method":"prompts/get","params":{"name":"review","arguments":{"code":"x"}}}') === $NotFound(2),
            description: "{$case}: prompts/get → -32601 Method not found"
         );
      }
      yield assert(
         assertion: $gets === [],
         description: 'an unregistered prompts/get never calls the Prompter'
      );

      // @ Prompts do not need resources, nor resources prompts
      $MCP = new MCP(name: 'Tester', version: '1', Lister: $Lister, Caller: $Caller, Prompts: $Prompts, Prompter: $Prompter);
      yield assert(
         assertion: $MCP->handle('{"jsonrpc":"2.0","id":3,"method":"resources/list"}') === $NotFound(3),
         description: 'a prompts-only server has no resources/list'
      );

      // @ prompts/list
      yield assert(
         assertion: $MCP->handle('{"jsonrpc":"2.0","id":4,"method":"prompts/list"}')
            === '{"jsonrpc":"2.0","id":4,"result":{"prompts":[{"name":"review","description":"Review code","arguments":[{"name":"code","required":true}]}]}}',
         description: 'prompts/list → {"prompts": the Prompts closure\'s list}'
      );
      $Empty = new MCP(name: 'Tester', version: '1', Lister: $Lister, Caller: $Caller, Prompts: static fn (): array => [], Prompter: $Prompter);
      yield assert(
         assertion: $Empty->handle('{"jsonrpc":"2.0","id":5,"method":"prompts/list"}') === '{"jsonrpc":"2.0","id":5,"result":{"prompts":[]}}',
         description: 'no prompts → {"prompts": []}'
      );

      // @ prompts/get
      yield assert(
         assertion: $MCP->handle('{"jsonrpc":"2.0","id":6,"method":"prompts/get","params":{"name":"review","arguments":{"code":"$a = 1;"}}}')
            === '{"jsonrpc":"2.0","id":6,"result":{"description":"Review code","messages":[{"role":"user","content":{"type":"text","text":"Review: $a = 1;"}}]}}',
         description: 'prompts/get → the Prompter\'s result, as it is'
      );
      yield assert(
         assertion: $gets === [['review', ['code' => '$a = 1;']]],
         description: 'the Prompter receives the name and the decoded arguments'
      );
      $gets = [];
      yield assert(
         assertion: $MCP->handle('{"jsonrpc":"2.0","id":7,"method":"prompts/get","params":{"name":"empty"}}') === '{"jsonrpc":"2.0","id":7,"result":{}}'
            && $gets === [['empty', []]],
         description: 'absent arguments → []; a Prompter returning [] → result {}'
      );
      yield assert(
         assertion: $MCP->handle('{"jsonrpc":"2.0","id":8,"method":"prompts/get","params":{"name":"nothing","arguments":{}}}') === '{"jsonrpc":"2.0","id":8,"result":{}}',
         description: 'a Prompter returning null → result {}'
      );
      yield assert(
         assertion: $MCP->handle('{"jsonrpc":"2.0","id":9,"method":"prompts/get","params":{"name":"unknown"}}')
            === '{"jsonrpc":"2.0","id":9,"error":{"code":-32602,"message":"Unknown prompt","data":{"name":"unknown"}}}',
         description: 'the Prompter\'s Fault for an unknown prompt reaches the client'
      );

      // @@ Invalid name or arguments → -32602, the Prompter never runs
      $gets = [];
      $invalid = '{"jsonrpc":"2.0","id":10,"error":{"code":-32602,"message":"Invalid params: name (string) and arguments (object) are required"}}';
      $cases = [
         'no params' => '{"jsonrpc":"2.0","id":10,"method":"prompts/get"}',
         'missing name' => '{"jsonrpc":"2.0","id":10,"method":"prompts/get","params":{"arguments":{}}}',
         'empty name' => '{"jsonrpc":"2.0","id":10,"method":"prompts/get","params":{"name":""}}',
         'integer name' => '{"jsonrpc":"2.0","id":10,"method":"prompts/get","params":{"name":7}}',
         'array name' => '{"jsonrpc":"2.0","id":10,"method":"prompts/get","params":{"name":["review"]}}',
         'string arguments' => '{"jsonrpc":"2.0","id":10,"method":"prompts/get","params":{"name":"review","arguments":"code"}}',
         'integer arguments' => '{"jsonrpc":"2.0","id":10,"method":"prompts/get","params":{"name":"review","arguments":3}}',
         'boolean arguments' => '{"jsonrpc":"2.0","id":10,"method":"prompts/get","params":{"name":"review","arguments":true}}'
      ];
      foreach ($cases as $case => $payload) {
         yield assert(
            assertion: $MCP->handle($payload) === $invalid,
            description: "prompts/get with {$case} → -32602 Invalid params"
         );
      }
      yield assert(
         assertion: $gets === [],
         description: 'no invalid prompts/get reached the Prompter'
      );
   }
);
