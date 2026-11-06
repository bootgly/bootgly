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
use Bootgly\API\RPC\JSON\Fault;
use Bootgly\API\RPC\MCP;


/**
 * `resources/list`, `resources/templates/list` and `resources/read` exist only
 * when both `Resources` and `Reader` are given — otherwise they are Method not
 * found. Templates default to `[]`; a read needs a non-empty string `uri`, and
 * the Reader's `RESOURCE_NOT_FOUND` Fault reaches the client as it is.
 */
return new Test(
   description: 'RPC\MCP: resources/list, resources/templates/list and resources/read',
   test: function () {
      // !
      $Lister = static fn (): array => [];
      $Caller = static fn (string $name, array $arguments): array => [];
      $Resources = static fn (): array => [
         ['uri' => 'file:///notes/a.md', 'name' => 'a.md', 'mimeType' => 'text/markdown']
      ];
      $Templates = static fn (): array => [
         ['uriTemplate' => 'file:///notes/{name}', 'name' => 'note']
      ];
      $reads = [];
      $Reader = static function (string $URI) use (&$reads): array {
         $reads[] = $URI;

         return match ($URI) {
            'file:///notes/a.md' => [['uri' => $URI, 'mimeType' => 'text/markdown', 'text' => '# A']],
            'file:///empty' => [],
            default => throw new Fault(MCP::RESOURCE_NOT_FOUND, 'Resource not found', ['uri' => $URI])
         };
      };
      $NotFound = static fn (int $id): string => "{\"jsonrpc\":\"2.0\",\"id\":{$id},\"error\":{\"code\":-32601,\"message\":\"Method not found\"}}";
      $methods = [
         'resources/list' => '{}',
         'resources/templates/list' => '{}',
         'resources/read' => '{"uri":"file:///notes/a.md"}'
      ];

      // @@ Not registered unless both Resources and Reader are given
      $Servers = [
         'neither' => new MCP(name: 'Tester', version: '1', Lister: $Lister, Caller: $Caller),
         'only Resources' => new MCP(name: 'Tester', version: '1', Lister: $Lister, Caller: $Caller, Resources: $Resources, Templates: $Templates),
         'only Reader' => new MCP(name: 'Tester', version: '1', Lister: $Lister, Caller: $Caller, Templates: $Templates, Reader: $Reader)
      ];
      foreach ($Servers as $case => $Server) {
         foreach ($methods as $method => $params) {
            yield assert(
               assertion: $Server->handle("{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"{$method}\",\"params\":{$params}}") === $NotFound(1),
               description: "{$case}: {$method} → -32601 Method not found"
            );
         }
      }
      yield assert(
         assertion: $reads === [],
         description: 'an unregistered resources/read never calls the Reader'
      );

      // @ Registered with both
      $MCP = new MCP(name: 'Tester', version: '1', Lister: $Lister, Caller: $Caller, Resources: $Resources, Reader: $Reader);
      yield assert(
         assertion: $MCP->handle('{"jsonrpc":"2.0","id":1,"method":"resources/list"}')
            === '{"jsonrpc":"2.0","id":1,"result":{"resources":[{"uri":"file:///notes/a.md","name":"a.md","mimeType":"text/markdown"}]}}',
         description: 'resources/list → {"resources": the Resources closure\'s list}'
      );
      yield assert(
         assertion: $MCP->handle('{"jsonrpc":"2.0","id":2,"method":"resources/templates/list"}')
            === '{"jsonrpc":"2.0","id":2,"result":{"resourceTemplates":[]}}',
         description: 'no Templates → resources/templates/list answers {"resourceTemplates": []}'
      );
      $Templated = new MCP(name: 'Tester', version: '1', Lister: $Lister, Caller: $Caller, Resources: $Resources, Templates: $Templates, Reader: $Reader);
      yield assert(
         assertion: $Templated->handle('{"jsonrpc":"2.0","id":3,"method":"resources/templates/list"}')
            === '{"jsonrpc":"2.0","id":3,"result":{"resourceTemplates":[{"uriTemplate":"file:///notes/{name}","name":"note"}]}}',
         description: 'Templates given → {"resourceTemplates": the Templates closure\'s list}'
      );
      $Empty = new MCP(name: 'Tester', version: '1', Lister: $Lister, Caller: $Caller, Resources: static fn (): array => [], Reader: $Reader);
      yield assert(
         assertion: $Empty->handle('{"jsonrpc":"2.0","id":4,"method":"resources/list"}') === '{"jsonrpc":"2.0","id":4,"result":{"resources":[]}}',
         description: 'no resources → {"resources": []}'
      );

      // @ resources/read
      $reads = [];
      yield assert(
         assertion: $MCP->handle('{"jsonrpc":"2.0","id":5,"method":"resources/read","params":{"uri":"file:///notes/a.md"}}')
            === '{"jsonrpc":"2.0","id":5,"result":{"contents":[{"uri":"file:///notes/a.md","mimeType":"text/markdown","text":"# A"}]}}',
         description: 'resources/read → {"contents": the Reader\'s contents}'
      );
      yield assert(
         assertion: $reads === ['file:///notes/a.md'],
         description: 'the Reader receives the URI'
      );
      yield assert(
         assertion: $MCP->handle('{"jsonrpc":"2.0","id":6,"method":"resources/read","params":{"uri":"file:///empty"}}')
            === '{"jsonrpc":"2.0","id":6,"result":{"contents":[]}}',
         description: 'a Reader returning [] → {"contents": []}'
      );
      yield assert(
         assertion: $MCP->handle('{"jsonrpc":"2.0","id":7,"method":"resources/read","params":{"uri":"file:///missing"}}')
            === '{"jsonrpc":"2.0","id":7,"error":{"code":-32002,"message":"Resource not found","data":{"uri":"file:///missing"}}}',
         description: 'the Reader\'s RESOURCE_NOT_FOUND Fault reaches the client: -32002, message and data'
      );

      // @@ Missing or invalid uri → -32602, the Reader never runs
      $reads = [];
      $invalid = '{"jsonrpc":"2.0","id":8,"error":{"code":-32602,"message":"Invalid params: uri (string) is required"}}';
      $cases = [
         'no params' => '{"jsonrpc":"2.0","id":8,"method":"resources/read"}',
         'missing uri' => '{"jsonrpc":"2.0","id":8,"method":"resources/read","params":{"name":"a.md"}}',
         'empty uri' => '{"jsonrpc":"2.0","id":8,"method":"resources/read","params":{"uri":""}}',
         'null uri' => '{"jsonrpc":"2.0","id":8,"method":"resources/read","params":{"uri":null}}',
         'integer uri' => '{"jsonrpc":"2.0","id":8,"method":"resources/read","params":{"uri":42}}',
         'array uri' => '{"jsonrpc":"2.0","id":8,"method":"resources/read","params":{"uri":["file:///notes/a.md"]}}'
      ];
      foreach ($cases as $case => $payload) {
         yield assert(
            assertion: $MCP->handle($payload) === $invalid,
            description: "resources/read with {$case} → -32602 Invalid params"
         );
      }
      yield assert(
         assertion: $reads === [],
         description: 'no invalid resources/read reached the Reader'
      );
   }
);
