<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ABI\Resources\Cache;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\API\RPC;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\MCP;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\MCP\Client;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * The GET listening stream: a session's client opens a `text/event-stream`
 * that stays open (heartbeats only); a second concurrent stream of the same
 * session is 409 — counted across workers; ending the session closes the
 * open stream within one supervision tick (5 s), after which the slot is
 * free and GET for the ended session is 404. GET without a session is 400,
 * and GET that does not accept an event stream is 406.
 */
$Server = new RPC\MCP(
   name: 'Spec',
   version: '1.0.0',
   Lister: static fn (mixed $context): array => [['name' => 'echo', 'inputSchema' => ['type' => 'object']]],
   Caller: static fn (string $name, array $arguments, mixed $context): array => [
      'content' => [['type' => 'text', 'text' => (string) json_encode($arguments)]],
      'isError' => false
   ]
);
$initialize = [
   'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
   'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => new stdClass, 'clientInfo' => ['name' => 'spec', 'version' => '1']]
];
$list = ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'];
$Transport = new MCP($Server, Cache: new Cache(['driver' => 'shared', 'segment' => 0x4d435053, 'prefix' => 'mcp-spec-1.7:']), streams: 1);
$Store = new Cache(['driver' => 'shared', 'segment' => 0x4d435053, 'prefix' => 'mcp-spec-1.7:']);
// ! Seconds left on the lease of the session's open stream (its slot in the session record)
$Lease = static function (string $id) use ($Store): int {
   $record = $Store->fetch("session:{$id}");
   $slots = is_array($record) && is_array($record['streams'] ?? null) ? $record['streams'] : [];

   return $slots === [] ? 0 : (int) max($slots) - time();
};
$Probe = new class {
   /** @var array<string,mixed> */
   public array $answers = [];
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),

   request: static function (string $hostPort, int $testIndex) use ($Probe, $initialize, $list, $Lease): string {
      try {
         $Init = Client::call($hostPort, $testIndex, $initialize);
         $id = $Init['headers']['mcp-session-id'] ?? '';
         $session = ['Mcp-Session-Id' => $id];

         // @ The first stream, held open on its own socket
         $Socket = stream_socket_client("tcp://{$hostPort}", $code, $message, 5);
         if ($Socket === false) {
            throw new RuntimeException("connect: {$message}");
         }
         fwrite($Socket, "GET /mcp HTTP/1.1\r\nHost: localhost\r\nX-Bootgly-Test: {$testIndex}\r\nAccept: text/event-stream\r\nMcp-Session-Id: {$id}\r\n\r\n");
         stream_set_blocking($Socket, false);
         $wire = '';
         $deadline = microtime(true) + 1.5;
         while (microtime(true) < $deadline && str_contains($wire, "\r\n\r\n") === false) {
            $read = [$Socket];
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, 0, 50_000) === 1) {
               $wire .= (string) fread($Socket, 65536);
            }
         }
         $opened = microtime(true);
         $Probe->answers['open'] = [
            (int) substr($wire, 9, 3),
            preg_match('/\r\nContent-Type: text\/event-stream/i', $wire) === 1
         ];

         // @ The open stream's slot is a short lease, not the session TTL
         $Probe->answers['lease'] = $Lease($id);

         // @ A second stream of the same session, while the first is open
         $Probe->answers['second'] = Client::exchange($hostPort, $testIndex, 'GET', $session + ['Accept' => 'text/event-stream'], timeout: 1.5)['code'];
         $Probe->answers['nosession'] = Client::exchange($hostPort, $testIndex, 'GET', ['Accept' => 'text/event-stream'])['code'];
         $Probe->answers['json'] = Client::exchange($hostPort, $testIndex, 'GET', $session + ['Accept' => 'application/json'])['code'];

         // @ Ticks (every 5 s) renew the slot with the lease again, never the
         //   session TTL: read in the third interval, after two ticks
         $wait = (int) ((10.5 - (microtime(true) - $opened)) * 1_000_000);
         if ($wait > 0) {
            usleep($wait);
         }
         $Probe->answers['renewed'] = $Lease($id);

         // @ Ending the session closes the open stream within a tick
         $Probe->answers['delete'] = Client::exchange($hostPort, $testIndex, 'DELETE', $session)['code'];
         $started = microtime(true);
         $closed = null;
         while (microtime(true) - $started < 8.0) {
            $read = [$Socket];
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, 0, 100_000) === 1) {
               $chunk = @fread($Socket, 65536);
               if ($chunk === false || $chunk === '') {
                  $closed = round(microtime(true) - $started, 2);
                  break;
               }
            }
         }
         @fclose($Socket);
         $Probe->answers['closed'] = $closed;
         $Probe->answers['ended'] = Client::exchange($hostPort, $testIndex, 'GET', $session + ['Accept' => 'text/event-stream'])['code'];

         // @ A new session gets its own stream slot
         $Again = Client::call($hostPort, $testIndex, $initialize);
         $Stream = Client::exchange($hostPort, $testIndex, 'GET', ['Mcp-Session-Id' => $Again['headers']['mcp-session-id'] ?? '', 'Accept' => 'text/event-stream'], timeout: 1.0);
         $Probe->answers['fresh'] = [$Stream['code'], $Stream['closed']];

         // @ A stream refused over HTTP/1.0 holds no slot
         $Other = Client::call($hostPort, $testIndex, $initialize);
         $other = ['Mcp-Session-Id' => $Other['headers']['mcp-session-id'] ?? '', 'Accept' => 'text/event-stream'];
         $Old = Client::exchange($hostPort, $testIndex, 'GET', $other, protocol: 'HTTP/1.0');
         $Stream = Client::exchange($hostPort, $testIndex, 'GET', $other, timeout: 1.0);
         $Probe->answers['legacy'] = [$Old['code'], $Stream['code']];
      }
      catch (Throwable $Throwable) {
         $Probe->error = "{$Throwable->getMessage()}";
      }

      return "GET /health HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: static function (Request $Request, Response $Response, Router $Router) use ($Transport): Generator {
      yield $Router->route('/mcp', static function (Request $Request, Response $Response) use ($Transport): Response {
         $Request->identity = $Request->Header->get('X-User');

         return $Transport($Request, $Response);
      }, [GET, POST, DELETE, PUT]);
   },

   test: new Assertions(Case: function (string $response) use ($Probe): Generator {
      $A = $Probe->answers;
      $evidence = json_encode(['error' => $Probe->error, 'answers' => $A]);

      yield new Assertion(
         description: 'GET opens a text/event-stream for the session',
         fallback: "Failed — {$evidence}"
      )
         ->expect($A['open'] ?? null)
         ->to->be([200, true])
         ->assert();

      yield new Assertion(
         description: 'an open stream holds its slot on a short lease (<= 15 s) that its ticks renew (past 10.5 s it is still >= 6 s)',
         fallback: "Failed — {$evidence}"
      )
         ->expect(
            ($A['lease'] ?? 0) > 0 && ($A['lease'] ?? 0) <= 15
            && ($A['renewed'] ?? 0) >= 6 && ($A['renewed'] ?? 0) <= 15
         )
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'a GET over HTTP/1.0 is 505 and frees its slot: the next GET opens a stream',
         fallback: "Failed — {$evidence}"
      )
         ->expect($A['legacy'] ?? null)
         ->to->be([505, 200])
         ->assert();

      yield new Assertion(
         description: 'a second concurrent stream of the session is 409',
         fallback: "Failed — {$evidence}"
      )
         ->expect($A['second'] ?? null)
         ->to->be(409)
         ->assert();

      yield new Assertion(
         description: 'GET without a session is 400; without accepting a stream 406',
         fallback: "Failed — {$evidence}"
      )
         ->expect([$A['nosession'] ?? null, $A['json'] ?? null])
         ->to->be([400, 406])
         ->assert();

      yield new Assertion(
         description: 'DELETE ends the session and its open stream closes within one tick',
         fallback: "Failed — {$evidence}"
      )
         ->expect(($A['delete'] ?? null) === 204 && is_float($A['closed'] ?? null) && $A['closed'] < 7.0)
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'GET for the ended session is 404; a new session opens its own stream',
         fallback: "Failed — {$evidence}"
      )
         ->expect([$A['ended'] ?? null, $A['fresh'] ?? null])
         ->to->be([404, [200, false]])
         ->assert();
   })
);
