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
 * The stream slots kept in a session record: a lapsed slot is pruned before
 * the count; a stream whose client leaves frees its slot at its `Close`, well
 * before its lease ends; an open stream keeps its session alive past the
 * TTL — its ticks renew it, and a request made meanwhile never shortens it
 * (with streams enabled each renewal grants at least the 15 s lease); a GET on a store full of expired sessions sweeps it and opens the
 * stream, while a store full of live sessions answers 503. A sweep stamp
 * that cannot be kept throttles the worker alone. The store here is a tiny
 * segment of its own (32 KiB).
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
$initialized = ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'];
$list = ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'];
$segment = 0x4d435055;
$Store = new Cache(['driver' => 'shared', 'segment' => $segment, 'size' => 32768, 'prefix' => 'mcp-spec-1.12:']);
$Transport = new MCP($Server, Cache: $Store, TTL: 60, streams: 1);
// ! A session TTL shorter than the wait: only the open stream keeps the session
$Shorts = new Cache(['driver' => 'shared', 'segment' => $segment, 'size' => 32768, 'prefix' => 'mcp-spec-1.12s:']);
$Short = new MCP($Server, Cache: $Shorts, TTL: 3, streams: 1);
$Probe = new class {
   /** @var array<string,mixed> */
   public array $answers = [];
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),

   request: static function (string $hostPort, int $testIndex) use ($Probe, $Server, $initialize, $initialized, $list, $Store, $Shorts): string {
      // ! A session resumed once (stored for the TTL, not provisionally)
      $Open = static function (array $headers = []) use ($hostPort, $testIndex, $initialize, $initialized): string {
         $Init = Client::call($hostPort, $testIndex, $initialize, $headers);
         $id = $Init['headers']['mcp-session-id'] ?? '';
         Client::call($hostPort, $testIndex, $initialized, $headers + ['Mcp-Session-Id' => $id]);

         return $id;
      };
      // ! Fill the store until it refuses (records, then the smallest keys)
      $Fill = static function (int $TTL) use ($Store): void {
         foreach (['session:', 'f:'] as $kind) {
            // @@ Until 300 refusals in a row
            for ($refused = 0; $refused < 300;) {
               try {
                  $Store->create($kind . bin2hex(random_bytes($kind === 'f:' ? 3 : 16)), $kind === 'f:' ? 1 : ['principal' => 'junk', 'revision' => 'x'], $TTL);
                  $refused = 0;
               }
               catch (RuntimeException) {
                  $refused++;
               }
            }
         }
      };
      $stream = ['Accept' => 'text/event-stream'];
      // ! A GET stream held open (its slot stays claimed): the status and refusal
      $Held = [];
      $Hold = static function (string $id) use ($hostPort, $testIndex, &$Held): array {
         $Socket = stream_socket_client("tcp://{$hostPort}", $code, $message, 5);
         if ($Socket === false) {
            throw new RuntimeException("connect: {$message}");
         }
         $Held[] = $Socket;
         fwrite($Socket, "GET /mcp HTTP/1.1\r\nHost: localhost\r\nX-Bootgly-Test: {$testIndex}\r\nAccept: text/event-stream\r\nMcp-Session-Id: {$id}\r\n\r\n");
         stream_set_timeout($Socket, 3);
         $wire = (string) fread($Socket, 65536);
         $separator = strpos($wire, "\r\n\r\n");
         $body = $separator === false ? '' : substr($wire, $separator + 4);

         return [(int) substr($wire, 9, 3), json_decode($body, true)['error']['message'] ?? null];
      };

      try {
         // @ A lapsed slot is not counted: the stream opens (streams: 1)
         $lapsed = bin2hex(random_bytes(16));
         $Store->create("session:{$lapsed}", [
            'principal' => hash('sha256', 'anonymous'),
            'revision' => '2025-06-18',
            'streams' => ['0123456789abcdef' => time() - 1]
         ], 60);
         $First = Client::exchange($hostPort, $testIndex, 'GET', $stream + ['Mcp-Session-Id' => $lapsed], timeout: 1.0);
         $left = microtime(true);
         $Probe->answers['pruned'] = [$First['code'], $First['closed']];

         // @ The client left (the socket is closed): its Close frees the slot
         //   within a tick, long before the lease (renewed by each tick) ends
         $Probe->answers['released'] = null;
         $polls = [];
         while (microtime(true) - $left < 14.0) {
            $Again = Client::exchange($hostPort, $testIndex, 'GET', $stream + ['Mcp-Session-Id' => $lapsed], timeout: 1.0);
            $polls[] = $Again['code'];
            if ($Again['code'] === 200) {
               $Probe->answers['released'] = round(microtime(true) - $left, 2);
               break;
            }
            usleep(500_000);
         }
         $Probe->answers['polls'] = $polls;

         // @ An open stream keeps its session alive past the TTL (3 s) — and
         //   past the 15 s its claim granted, so only its ticks can hold it —
         //   while a request made meanwhile renews it no shorter
         $short = $Open(['X-Store' => 'short']);
         $Socket = stream_socket_client("tcp://{$hostPort}", $code, $message, 5);
         if ($Socket === false) {
            throw new RuntimeException("connect: {$message}");
         }
         fwrite($Socket, "GET /mcp HTTP/1.1\r\nHost: localhost\r\nX-Bootgly-Test: {$testIndex}\r\nX-Store: short\r\nAccept: text/event-stream\r\nMcp-Session-Id: {$short}\r\n\r\n");
         stream_set_blocking($Socket, false);
         $wire = '';
         $eof = false;
         $opened = microtime(true);
         $during = null;
         while (microtime(true) - $opened < 21.0) {
            // @ One request a second after the stream opened
            if ($during === null && microtime(true) - $opened > 1.0) {
               $during = Client::call($hostPort, $testIndex, $list, ['X-Store' => 'short', 'Mcp-Session-Id' => $short])['code'];
               // ! What the request's renewal granted: the lease, not the TTL
               $Probe->answers['granted'] = $Shorts->remain("session:{$short}");
            }
            $read = [$Socket];
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, 0, 100_000) === 1) {
               $chunk = @fread($Socket, 65536);
               if ($chunk === false || $chunk === '') {
                  $eof = true;
                  break;
               }
               $wire .= $chunk;
            }
         }
         $Probe->answers['alive'] = [
            (int) substr($wire, 9, 3),
            $during,
            $eof,
            Client::call($hostPort, $testIndex, $list, ['X-Store' => 'short', 'Mcp-Session-Id' => $short])['code']
         ];
         @fclose($Socket);

         // @ A store full of expired sessions: a GET sweeps it, and every
         //   stream opens — more than the room a filler entry leaves holds
         $Sweeps = [];
         for ($index = 0; $index < 8; $index++) {
            $Sweeps[] = $Open();
         }
         $Fill(1);
         usleep(2_200_000);
         $codes = [];
         foreach ($Sweeps as $swept) {
            $codes[] = $Hold($swept)[0];
         }
         $Probe->answers['swept'] = $codes;

         // @ A store full of live sessions: 503 — streams held open, so each
         //   claim keeps its slot, until the room a filler entry leaves is gone
         $Lives = [];
         for ($index = 0; $index < 8; $index++) {
            $Lives[] = $Open();
         }
         $Fill(60);
         $codes = [];
         $Probe->answers['full'] = null;
         foreach ($Lives as $live) {
            [$code, $refusal] = $Hold($live);
            $codes[] = $code;
            if ($code !== 200) {
               $Probe->answers['full'] = [$code, $refusal];
               break;
            }
         }
         $Probe->answers['claims'] = $codes;

         // @ A sweep stamp that cannot be kept throttles the worker alone
         $Local = new MCP($Server, Cache: $Store);
         $Refusing = new Cache(['driver' => 'shared', 'segment' => 0x4d435056, 'size' => 8192, 'prefix' => 'mcp-spec-1.12t:']);
         // @@ Until the stamp segment refuses every key
         for ($refused = 0; $refused < 300;) {
            try {
               $Refusing->create(bin2hex(random_bytes(4)), 1, 60);
               $refused = 0;
            }
            catch (RuntimeException) {
               $refused++;
            }
         }
         $Probe->answers['fallback'] = Closure::bind(function () use ($Refusing): array {
            $this->Stamps = $Refusing;
            $turns = [$this->stamp(), $this->stamp()];
            $this->swept -= 5;
            $turns[] = $this->stamp();

            return $turns;
         }, $Local, MCP::class)();
      }
      catch (Throwable $Throwable) {
         $Probe->error = "{$Throwable->getMessage()}";
      }
      // @@ Every held stream ends
      foreach ($Held as $Socket) {
         @fclose($Socket);
      }

      return "GET /health HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: static function (Request $Request, Response $Response, Router $Router) use ($Transport, $Short): Generator {
      yield $Router->route('/mcp', static function (Request $Request, Response $Response) use ($Transport, $Short): Response {
         $Handler = $Request->Header->get('X-Store') === 'short' ? $Short : $Transport;

         return $Handler($Request, $Response);
      }, [GET, POST, DELETE]);
   },

   test: new Assertions(Case: function (string $response) use ($Probe): Generator {
      $A = $Probe->answers;
      $evidence = json_encode(['error' => $Probe->error, 'answers' => $A]);

      yield new Assertion(
         description: 'a lapsed slot is pruned before the count: the stream opens',
         fallback: "Failed — {$evidence}"
      )
         ->expect($A['pruned'] ?? null)
         ->to->be([200, false])
         ->assert();

      yield new Assertion(
         description: "a stream whose client left frees its slot at its Close, before its lease (15 s, renewed by ticks) ends",
         fallback: "Failed — {$evidence}"
      )
         ->expect(is_numeric($A['released'] ?? null) && $A['released'] < 12.0)
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'an open stream keeps its session alive past the TTL and its claim (21 s), a request meanwhile included',
         fallback: "Failed — {$evidence}"
      )
         ->expect($A['alive'] ?? null)
         ->to->be([200, 200, false, 200])
         ->assert();

      yield new Assertion(
         description: 'with streams enabled, a renewal grants at least the 15 s lease, however short the TTL (3 s)',
         fallback: "Failed — {$evidence}"
      )
         ->expect(($A['granted'] ?? 0) >= 14 && ($A['granted'] ?? 0) <= 15)
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'GETs on a store full of expired sessions sweep it: every stream opens',
         fallback: "Failed — {$evidence}"
      )
         ->expect($A['swept'] ?? null)
         ->to->be([200, 200, 200, 200, 200, 200, 200, 200])
         ->assert();

      yield new Assertion(
         description: 'a GET on a store full of live sessions is 503 (after at most the claims the room left holds)',
         fallback: "Failed — {$evidence}"
      )
         ->expect([$A['full'] ?? null, count($A['claims'] ?? []) <= 4])
         ->to->be([[503, 'Service Unavailable: the session store cannot hold a new stream'], true])
         ->assert();

      yield new Assertion(
         description: 'a sweep stamp that cannot be kept gives this worker one turn every 5 s',
         fallback: "Failed — {$evidence}"
      )
         ->expect($A['fallback'] ?? null)
         ->to->be([true, false, true])
         ->assert();
   })
);
