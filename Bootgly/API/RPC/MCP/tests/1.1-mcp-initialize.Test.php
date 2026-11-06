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


use function array_key_exists;
use function array_keys;
use function assert;
use function count;
use function is_array;
use function json_decode;
use function json_encode;
use RuntimeException;
use stdClass;
use Throwable;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\RPC\MCP;


/**
 * `initialize` answers the agreed revision (the requested one when this server
 * speaks it, the newest otherwise), the capabilities — tools always, resources
 * and prompts only when both of their closures are given —, the server info
 * and, only when given, the instructions. The Initializer is told the agreed
 * revision and the client's params inside the same `handle()` call.
 */
return new Test(
   description: 'RPC\MCP: initialize — negotiation, capabilities, serverInfo, instructions, Initializer',
   test: function () {
      // !
      $Lister = static fn (): array => [];
      $Caller = static fn (string $name, array $arguments): array => [];
      $Listing = static fn (): array => [];
      $Reader = static fn (string $URI): array => [];
      $Prompter = static fn (string $name, array $arguments): array => [];
      $Initialize = static function (MCP $MCP, mixed $params = null, bool $absent = false): array {
         $request = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'];
         if ($absent === false) {
            $request['params'] = $params;
         }
         $answer = json_decode((string) $MCP->handle((string) json_encode($request)), associative: true);

         return is_array($answer) ? $answer : [];
      };
      $MCP = new MCP(name: 'Tester', version: '1.2.3', Lister: $Lister, Caller: $Caller);

      // @@ Every revision the server speaks is kept
      foreach (MCP::VERSIONS as $revision) {
         $answer = $Initialize($MCP, ['protocolVersion' => $revision]);

         yield assert(
            assertion: ($answer['result']['protocolVersion'] ?? null) === $revision,
            description: "a client asking for {$revision} gets {$revision}"
         );
      }

      // @@ An unknown revision gets the newest
      foreach (['2099-01-01', '2024-11-04', '', ' 2025-06-18', '2025-06-18 ', 'latest'] as $revision) {
         $answer = $Initialize($MCP, ['protocolVersion' => $revision]);

         yield assert(
            assertion: ($answer['result']['protocolVersion'] ?? null) === MCP::VERSIONS[0],
            description: "an unknown revision \"{$revision}\" gets the newest"
         );
      }

      // @@ A non-string revision gets the newest
      foreach ([20250618, 2025.06, true, null, ['2025-06-18'], ['v' => '2025-06-18']] as $index => $revision) {
         $answer = $Initialize($MCP, ['protocolVersion' => $revision]);

         yield assert(
            assertion: ($answer['result']['protocolVersion'] ?? null) === MCP::VERSIONS[0],
            description: "a non-string protocolVersion (case {$index}) gets the newest"
         );
      }

      // @ An absent revision — no protocolVersion, empty params, no params — gets the newest
      $answer = $Initialize($MCP, ['clientInfo' => ['name' => 'Client', 'version' => '1']]);
      yield assert(
         assertion: ($answer['result']['protocolVersion'] ?? null) === MCP::VERSIONS[0],
         description: 'params without protocolVersion → the newest revision'
      );
      $answer = $Initialize($MCP, new stdClass);
      yield assert(
         assertion: ($answer['result']['protocolVersion'] ?? null) === MCP::VERSIONS[0],
         description: 'empty params {} → the newest revision'
      );
      $answer = $Initialize($MCP, absent: true);
      yield assert(
         assertion: ($answer['result']['protocolVersion'] ?? null) === MCP::VERSIONS[0],
         description: 'no params at all → the newest revision'
      );

      // @ The whole answer, byte for byte
      yield assert(
         assertion: $MCP->handle('{"jsonrpc":"2.0","id":7,"method":"initialize","params":{"protocolVersion":"2025-06-18"}}')
            === '{"jsonrpc":"2.0","id":7,"result":{"protocolVersion":"2025-06-18","capabilities":{"tools":{"listChanged":false}},"serverInfo":{"name":"Tester","version":"1.2.3"}}}',
         description: 'tools-only server: protocolVersion, capabilities.tools and serverInfo, nothing else'
      );

      // @ serverInfo carries name and version
      $answer = $Initialize($MCP, ['protocolVersion' => '2025-11-25']);
      yield assert(
         assertion: ($answer['result']['serverInfo'] ?? null) === ['name' => 'Tester', 'version' => '1.2.3'],
         description: 'serverInfo is exactly {name, version}'
      );

      // @ Instructions only when given
      yield assert(
         assertion: array_key_exists('instructions', $answer['result'] ?? []) === false
            && array_keys($answer['result'] ?? []) === ['protocolVersion', 'capabilities', 'serverInfo'],
         description: 'no instructions given → no instructions member'
      );
      $Instructed = new MCP(name: 'Tester', version: '1.2.3', Lister: $Lister, Caller: $Caller, instructions: 'Use the echo tool.');
      $answer = $Initialize($Instructed, ['protocolVersion' => '2025-11-25']);
      yield assert(
         assertion: ($answer['result']['instructions'] ?? null) === 'Use the echo tool.'
            && array_keys($answer['result'] ?? []) === ['protocolVersion', 'capabilities', 'serverInfo', 'instructions'],
         description: 'instructions given → sent as the last member'
      );
      $Instructed = new MCP(name: 'Tester', version: '1.2.3', Lister: $Lister, Caller: $Caller, instructions: '');
      $answer = $Initialize($Instructed, ['protocolVersion' => '2025-11-25']);
      yield assert(
         assertion: array_key_exists('instructions', $answer['result'] ?? []) && $answer['result']['instructions'] === '',
         description: 'empty instructions are still given → sent as ""'
      );

      // @ Capabilities: tools always; resources and prompts only with both closures
      $Capabilities = static fn (MCP $MCP): mixed => $Initialize($MCP, ['protocolVersion' => '2025-11-25'])['result']['capabilities'] ?? null;
      $tools = ['listChanged' => false];
      $resources = ['listChanged' => false, 'subscribe' => false];
      $prompts = ['listChanged' => false];

      yield assert(
         assertion: $Capabilities($MCP) === ['tools' => $tools],
         description: 'no resources nor prompts → capabilities is {tools} only'
      );
      yield assert(
         assertion: $Capabilities(new MCP(
            name: 'Tester', version: '1', Lister: $Lister, Caller: $Caller, Resources: $Listing, Reader: $Reader
         )) === ['tools' => $tools, 'resources' => $resources],
         description: 'Resources + Reader → resources {listChanged: false, subscribe: false}'
      );
      yield assert(
         assertion: $Capabilities(new MCP(
            name: 'Tester', version: '1', Lister: $Lister, Caller: $Caller, Resources: $Listing, Templates: $Listing
         )) === ['tools' => $tools],
         description: 'Resources (and Templates) without Reader → no resources capability'
      );
      yield assert(
         assertion: $Capabilities(new MCP(
            name: 'Tester', version: '1', Lister: $Lister, Caller: $Caller, Reader: $Reader
         )) === ['tools' => $tools],
         description: 'Reader without Resources → no resources capability'
      );
      yield assert(
         assertion: $Capabilities(new MCP(
            name: 'Tester', version: '1', Lister: $Lister, Caller: $Caller, Prompts: $Listing, Prompter: $Prompter
         )) === ['tools' => $tools, 'prompts' => $prompts],
         description: 'Prompts + Prompter → prompts {listChanged: false}'
      );
      yield assert(
         assertion: $Capabilities(new MCP(
            name: 'Tester', version: '1', Lister: $Lister, Caller: $Caller, Prompts: $Listing
         )) === ['tools' => $tools],
         description: 'Prompts without Prompter → no prompts capability'
      );
      yield assert(
         assertion: $Capabilities(new MCP(
            name: 'Tester', version: '1', Lister: $Lister, Caller: $Caller, Prompter: $Prompter
         )) === ['tools' => $tools],
         description: 'Prompter without Prompts → no prompts capability'
      );
      yield assert(
         assertion: $Capabilities(new MCP(
            name: 'Tester', version: '1', Lister: $Lister, Caller: $Caller,
            Resources: $Listing, Reader: $Reader, Prompts: $Listing, Prompter: $Prompter
         )) === ['tools' => $tools, 'resources' => $resources, 'prompts' => $prompts],
         description: 'everything given → tools, resources and prompts, in that order'
      );

      // @ The Initializer: the agreed revision and the params, inside handle()
      $calls = [];
      $Initialized = new MCP(
         name: 'Tester', version: '1', Lister: $Lister, Caller: $Caller,
         Initializer: static function (string $revision, array $params) use (&$calls): void {
            $calls[] = [$revision, $params];
         }
      );
      $params = [
         'protocolVersion' => '2025-03-26',
         'capabilities' => ['roots' => ['listChanged' => true]],
         'clientInfo' => ['name' => 'Client', 'version' => '9.9']
      ];
      $answer = $Initialize($Initialized, $params);
      yield assert(
         assertion: count($calls) === 1,
         description: 'the Initializer has run once by the time handle() returns'
      );
      yield assert(
         assertion: ($calls[0][0] ?? null) === '2025-03-26' && ($answer['result']['protocolVersion'] ?? null) === '2025-03-26',
         description: 'it receives the agreed revision — the one answered'
      );
      yield assert(
         assertion: ($calls[0][1] ?? null) === $params,
         description: 'it receives the client params as decoded'
      );

      $calls = [];
      $Initialize($Initialized, ['protocolVersion' => '1999-01-01', 'clientInfo' => ['name' => 'Old']]);
      yield assert(
         assertion: ($calls[0][0] ?? null) === MCP::VERSIONS[0]
            && ($calls[0][1] ?? null) === ['protocolVersion' => '1999-01-01', 'clientInfo' => ['name' => 'Old']],
         description: 'an unknown revision: the Initializer gets the newest, the params keep what the client sent'
      );

      $calls = [];
      $Initialize($Initialized, absent: true);
      yield assert(
         assertion: $calls === [[MCP::VERSIONS[0], []]],
         description: 'no params: the Initializer gets the newest revision and []'
      );

      $calls = [];
      $Initialized->handle('{"jsonrpc":"2.0","id":1,"method":"ping"}');
      $Initialized->handle('{"jsonrpc":"2.0","id":2,"method":"tools/list"}');
      $Initialized->handle('{"jsonrpc":"2.0","method":"notifications/initialized"}');
      $Initialized->handle('{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"x"}}');
      yield assert(
         assertion: $calls === [],
         description: 'no other method calls the Initializer'
      );

      // @ The Initializer runs before the answer: its failure is the answer
      $Reported = [];
      $Failing = new MCP(
         name: 'Tester', version: '1', Lister: $Lister, Caller: $Caller,
         Initializer: static function (string $revision, array $params): void {
            throw new RuntimeException('session store down');
         },
         Reporter: static function (Throwable $Throwable, array $request) use (&$Reported): void {
            $Reported[] = $Throwable;
         }
      );
      yield assert(
         assertion: $Failing->handle('{"jsonrpc":"2.0","id":5,"method":"initialize","params":{"protocolVersion":"2025-06-18"}}')
            === '{"jsonrpc":"2.0","id":5,"error":{"code":-32603,"message":"Internal error"}}',
         description: 'an Initializer failure makes initialize an Internal error, without its message'
      );
      yield assert(
         assertion: count($Reported) === 1 && $Reported[0]->getMessage() === 'session store down',
         description: 'and reaches the Reporter'
      );
   }
);
