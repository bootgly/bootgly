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


use function array_keys;
use function assert;
use function count;
use InvalidArgumentException;
use JsonException;
use LogicException;
use ReflectionProperty;
use Throwable;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\RPC\JSON;
use Bootgly\API\RPC\MCP;


/**
 * The protocol methods are registered on one inner `RPC\JSON` dispatcher,
 * exposed read-only as `MCP->JSON`; `handle()` is that dispatcher's answer.
 * The `Reporter` and `batch` arguments reach it as `JSON` takes them.
 */
return new Test(
   description: 'RPC\MCP: the inner JSON-RPC dispatcher — methods, handle(), Reporter and batch',
   test: function () {
      // !
      $Lister = static fn (): array => [];
      $Caller = static fn (string $name, array $arguments): array => [];
      $Listing = static fn (): array => [];
      $Reader = static fn (string $URI): array => [];
      $Prompter = static fn (string $name, array $arguments): array => [];
      $Methods = new ReflectionProperty(JSON::class, 'Methods');
      $Registered = static fn (MCP $MCP): array => array_keys((array) $Methods->getValue($MCP->JSON));
      $base = ['initialize', 'notifications/initialized', 'notifications/cancelled', 'ping', 'tools/list', 'tools/call'];
      $MCP = new MCP(name: 'Tester', version: '1', Lister: $Lister, Caller: $Caller);

      // @ The dispatcher
      yield assert(
         assertion: $MCP->JSON instanceof JSON,
         description: 'MCP->JSON is an RPC\JSON dispatcher'
      );
      $Property = new ReflectionProperty(MCP::class, 'JSON');
      yield assert(
         assertion: $Property->isPublic() && $Property->isPrivateSet(),
         description: 'MCP->JSON is public private(set)'
      );
      $refused = false;
      try {
         $MCP->JSON = new JSON([]); // @phpstan-ignore-line
      }
      catch (Throwable) {
         $refused = true;
      }
      yield assert(
         assertion: $refused,
         description: 'MCP->JSON cannot be replaced from outside'
      );

      // @ The registered methods
      yield assert(
         assertion: $Registered($MCP) === $base,
         description: 'tools only: initialize, the two notifications, ping, tools/list, tools/call'
      );
      yield assert(
         assertion: $Registered(new MCP(name: 'Tester', version: '1', Lister: $Lister, Caller: $Caller, Resources: $Listing, Reader: $Reader))
            === [...$base, 'resources/list', 'resources/templates/list', 'resources/read'],
         description: 'Resources + Reader add resources/list, resources/templates/list and resources/read'
      );
      yield assert(
         assertion: $Registered(new MCP(name: 'Tester', version: '1', Lister: $Lister, Caller: $Caller, Prompts: $Listing, Prompter: $Prompter))
            === [...$base, 'prompts/list', 'prompts/get'],
         description: 'Prompts + Prompter add prompts/list and prompts/get'
      );
      yield assert(
         assertion: $Registered(new MCP(
            name: 'Tester', version: '1', Lister: $Lister, Caller: $Caller,
            Resources: $Listing, Templates: $Listing, Reader: $Reader, Prompts: $Listing, Prompter: $Prompter
         )) === [...$base, 'resources/list', 'resources/templates/list', 'resources/read', 'prompts/list', 'prompts/get'],
         description: 'everything given: all eleven methods'
      );

      // @@ handle() is the dispatcher's answer
      $payloads = [
         '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18"}}',
         '{"jsonrpc":"2.0","id":2,"method":"ping"}',
         '{"jsonrpc":"2.0","method":"notifications/initialized"}',
         '[{"jsonrpc":"2.0","id":3,"method":"tools/list"},{"jsonrpc":"2.0","id":4,"method":"nope"}]',
         'not json',
         '',
         '[]'
      ];
      foreach ($payloads as $index => $payload) {
         yield assert(
            assertion: $MCP->handle($payload) === $MCP->JSON->handle($payload),
            description: "handle() answers what MCP->JSON->handle() answers (payload {$index})"
         );
      }

      yield assert(
         assertion: $MCP->handle('') === '{"jsonrpc":"2.0","id":null,"error":{"code":-32700,"message":"Parse error"}}',
         description: 'an empty payload is a Parse error, never null'
      );

      // @ The batch limit reaches the dispatcher
      yield assert(
         assertion: $MCP->JSON->batch === 100,
         description: 'the default batch limit is 100'
      );
      $Limited = new MCP(name: 'Tester', version: '1', Lister: $Lister, Caller: $Caller, batch: 2);
      yield assert(
         assertion: $Limited->JSON->batch === 2,
         description: 'batch: 2 → MCP->JSON->batch is 2'
      );
      yield assert(
         assertion: $Limited->handle('[{"jsonrpc":"2.0","id":1,"method":"ping"},{"jsonrpc":"2.0","id":2,"method":"ping"}]')
            === '[{"jsonrpc":"2.0","id":1,"result":{}},{"jsonrpc":"2.0","id":2,"result":{}}]',
         description: 'a batch at the limit is answered'
      );
      yield assert(
         assertion: $Limited->handle('[{"jsonrpc":"2.0","id":1,"method":"ping"},{"jsonrpc":"2.0","id":2,"method":"ping"},{"jsonrpc":"2.0","id":3,"method":"ping"}]')
            === '{"jsonrpc":"2.0","id":null,"error":{"code":-32600,"message":"Invalid Request"}}',
         description: 'a batch past the limit is refused whole with one Invalid Request'
      );
      $Unbatched = new MCP(name: 'Tester', version: '1', Lister: $Lister, Caller: $Caller, batch: 0);
      yield assert(
         assertion: $Unbatched->JSON->batch === 0
            && $Unbatched->handle('[{"jsonrpc":"2.0","id":1,"method":"ping"}]')
               === '{"jsonrpc":"2.0","id":null,"error":{"code":-32600,"message":"Invalid Request"}}',
         description: 'batch: 0 refuses every batch'
      );
      yield assert(
         assertion: $Unbatched->handle('{"jsonrpc":"2.0","id":1,"method":"ping"}') === '{"jsonrpc":"2.0","id":1,"result":{}}',
         description: 'batch: 0 still answers single requests'
      );
      $thrown = null;
      try {
         new MCP(name: 'Tester', version: '1', Lister: $Lister, Caller: $Caller, batch: -1);
      }
      catch (InvalidArgumentException $Exception) {
         $thrown = $Exception->getMessage();
      }
      yield assert(
         assertion: $thrown === 'The batch limit cannot be negative (-1).',
         description: 'a negative batch is refused by the dispatcher with InvalidArgumentException'
      );

      // @ The Reporter reaches the dispatcher
      $Reported = [];
      $Reporter = static function (Throwable $Throwable, array $request) use (&$Reported): void {
         $Reported[] = [$Throwable, $request];
      };
      $Reporting = new MCP(
         name: 'Tester',
         version: '1',
         Lister: static fn (): array => throw new LogicException('lister broke'),
         Caller: static fn (string $name, array $arguments): array => ['content' => [['type' => 'text', 'text' => "\xff"]], 'isError' => false],
         Reporter: $Reporter
      );
      yield assert(
         assertion: $Reporting->handle('{"jsonrpc":"2.0","id":1,"method":"tools/list"}')
            === '{"jsonrpc":"2.0","id":1,"error":{"code":-32603,"message":"Internal error"}}',
         description: 'a method failure → -32603 Internal error'
      );
      yield assert(
         assertion: count($Reported) === 1
            && $Reported[0][0] instanceof LogicException
            && $Reported[0][0]->getMessage() === 'lister broke'
            && $Reported[0][1] === ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
         description: 'the Reporter receives the method\'s Throwable and its request'
      );
      $Reported = [];
      yield assert(
         assertion: $Reporting->handle('{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"bin"}}')
            === '{"jsonrpc":"2.0","id":2,"error":{"code":-32603,"message":"Internal error"}}',
         description: 'a result that cannot be encoded → -32603 for the same id'
      );
      yield assert(
         assertion: count($Reported) === 1
            && $Reported[0][0] instanceof JsonException
            && ($Reported[0][1]['method'] ?? null) === 'tools/call',
         description: 'the Reporter receives the encoding failure and its request'
      );
      $Reported = [];
      $Reporting->handle('{"jsonrpc":"2.0","id":3,"method":"ping"}');
      $Reporting->handle('{"jsonrpc":"2.0","id":4,"method":"unknown"}');
      yield assert(
         assertion: $Reported === [],
         description: 'answers and designed errors are not reported'
      );

      // @ Without a Reporter, a failure is still an Internal error
      $Silent = new MCP(
         name: 'Tester',
         version: '1',
         Lister: static fn (): array => throw new LogicException('lister broke'),
         Caller: $Caller
      );
      yield assert(
         assertion: $Silent->handle('{"jsonrpc":"2.0","id":9,"method":"tools/list"}')
            === '{"jsonrpc":"2.0","id":9,"error":{"code":-32603,"message":"Internal error"}}',
         description: 'no Reporter: the failure is still -32603, nothing thrown'
      );
   }
);
