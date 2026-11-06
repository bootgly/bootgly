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
use Fiber;
use RuntimeException;
use stdClass;
use Throwable;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\RPC\MCP;


/**
 * One instance shared by two clients whose calls interleave in Fibers: client
 * A's tools/call suspends inside the Caller (async I/O), client B initializes
 * another revision and suspends in its own tools/call meanwhile, then A's tool
 * fails and A initializes. Because each call carries its own context — the
 * client's session — every Initializer, Caller and Reporter call sees its own
 * client's, never the one that ran last.
 */
return new Test(
   description: 'RPC\MCP: interleaved Fibers — each call sees its own context',
   test: function () {
      // !
      $Sessions = [];
      foreach (['A', 'B'] as $id) {
         $Session = new stdClass;
         $Session->id = $id;
         $Session->revision = null;
         $Sessions[$id] = $Session;
      }
      $initialized = [];
      $called = [];
      $reported = [];
      $MCP = new MCP(
         name: 'Weather',
         version: '1.0.0',
         Lister: static fn (mixed $context): array => [],
         Caller: static function (string $name, array $arguments, mixed $Session) use (&$called): array {
            $called[] = "{$Session->id}:before";

            // @ Async I/O: the Fiber yields to the other client
            Fiber::suspend($Session->id);

            $called[] = "{$Session->id}:after";

            // ?
            if ($name === 'boom') {
               throw new RuntimeException("db down for {$Session->id}");
            }

            // :
            return ['content' => [['type' => 'text', 'text' => $Session->id]], 'isError' => false];
         },
         Initializer: static function (string $revision, array $params, mixed $Session) use (&$initialized): void {
            $Session->revision = $revision;
            $initialized[] = [$Session->id, $revision, $params['clientInfo']['name'] ?? null];
         },
         Reporter: static function (Throwable $Throwable, array $request, mixed $Session) use (&$reported): void {
            $reported[] = [$Session->id, $Throwable->getMessage(), $request['id'] ?? null];
         }
      );
      $answers = [];
      $A = new Fiber(static function () use ($MCP, $Sessions, &$answers): void {
         $answers['A:call'] = $MCP->handle('{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"boom"}}', $Sessions['A']);
         $answers['A:initialize'] = $MCP->handle('{"jsonrpc":"2.0","id":2,"method":"initialize","params":{"protocolVersion":"2024-11-05","clientInfo":{"name":"ClientA"}}}', $Sessions['A']);
      });
      $B = new Fiber(static function () use ($MCP, $Sessions, &$answers): void {
         $answers['B:initialize'] = $MCP->handle('{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","clientInfo":{"name":"ClientB"}}}', $Sessions['B']);
         $answers['B:call'] = $MCP->handle('{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"echo"}}', $Sessions['B']);
      });

      // @ Interleave: A suspends, B initializes and suspends, A resumes and finishes, B resumes
      $suspended = [$A->start(), $B->start()];
      $A->resume();
      $B->resume();

      yield assert(
         assertion: $suspended === ['A', 'B'] && $A->isTerminated() && $B->isTerminated(),
         description: 'A suspended in its tool, then B in its own; both ran to the end'
      );
      yield assert(
         assertion: $called === ['A:before', 'B:before', 'A:after', 'B:after'],
         description: 'each Caller saw its own client\'s context before and after suspending'
      );
      yield assert(
         assertion: $initialized === [['B', '2025-06-18', 'ClientB'], ['A', '2024-11-05', 'ClientA']],
         description: 'B\'s Initializer, while A was suspended, saw B; A\'s, after B, saw A'
      );
      yield assert(
         assertion: $Sessions['A']->revision === '2024-11-05' && $Sessions['B']->revision === '2025-06-18',
         description: 'each session holds its own client\'s revision'
      );
      yield assert(
         assertion: $reported === [['A', 'db down for A', 1]],
         description: 'A\'s tool failure, after B ran, reached the Reporter with A\'s context'
      );
      yield assert(
         assertion: $answers === [
            'B:initialize' => '{"jsonrpc":"2.0","id":1,"result":{"protocolVersion":"2025-06-18","capabilities":{"tools":{"listChanged":false}},"serverInfo":{"name":"Weather","version":"1.0.0"}}}',
            'A:call' => '{"jsonrpc":"2.0","id":1,"error":{"code":-32603,"message":"Internal error"}}',
            'A:initialize' => '{"jsonrpc":"2.0","id":2,"result":{"protocolVersion":"2024-11-05","capabilities":{"tools":{"listChanged":false}},"serverInfo":{"name":"Weather","version":"1.0.0"}}}',
            'B:call' => '{"jsonrpc":"2.0","id":2,"result":{"content":[{"type":"text","text":"B"}],"isError":false}}'
         ],
         description: 'each client got its own answers, with its own ids and revision'
      );

      // @ The Reporter's context, when B's tool fails after A's ran
      $reported = [];
      $C = new Fiber(static fn (): null|string => $MCP->handle('{"jsonrpc":"2.0","id":7,"method":"tools/call","params":{"name":"echo"}}', $Sessions['A']));
      $D = new Fiber(static fn (): null|string => $MCP->handle('{"jsonrpc":"2.0","id":8,"method":"tools/call","params":{"name":"boom"}}', $Sessions['B']));
      $C->start();
      $D->start();
      $D->resume();
      $C->resume();
      yield assert(
         assertion: $reported === [['B', 'db down for B', 8]]
            && $C->getReturn() === '{"jsonrpc":"2.0","id":7,"result":{"content":[{"type":"text","text":"A"}],"isError":false}}'
            && $D->getReturn() === '{"jsonrpc":"2.0","id":8,"error":{"code":-32603,"message":"Internal error"}}',
         description: 'B\'s failure, resumed before A, is reported with B\'s context; A still answers its own'
      );
   }
);
