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
 * A full session store refuses new sessions with 503 — and recovers on its
 * own once they expire: the transport sweeps expired sessions out of a full
 * store and tries again, so `initialize` calls never resumed cannot keep it
 * full. One worker sweeps at most every 5 s per store: while the stamp of
 * the last sweep lives, a refusal sweeps nothing even when every session
 * has expired. The store here is a tiny segment of its own (32 KiB, TTL 2 s).
 */
$Server = new RPC\MCP(
   name: 'Spec',
   version: '1.0.0',
   Lister: static fn (mixed $context): array => [],
   Caller: static fn (string $name, array $arguments, mixed $context): array => ['content' => []]
);
$initialize = [
   'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
   'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => new stdClass, 'clientInfo' => ['name' => 'spec', 'version' => '1']]
];
$segment = 0x4d435054;
$Transport = new MCP(
   $Server,
   Cache: new Cache(['driver' => 'shared', 'segment' => $segment, 'size' => 32768, 'prefix' => 'mcp-spec-1.11:']),
   TTL: 2
);
// ! The store's sweep stamp (the transport's own segment, derived from the store)
$scope = defined('BOOTGLY_WORKING_DIR') ? (string) constant('BOOTGLY_WORKING_DIR') : '';
$Stamps = new Cache(['driver' => 'shared', 'segment' => crc32("bootgly.mcp.sweeps\0{$scope}\0shared\0{$segment}\0mcp-spec-1.11:") & 0x7fffffff, 'size' => 65_536, 'prefix' => 'mcp:']);
$Probe = new class {
   /** @var array<string,mixed> */
   public array $answers = [];
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),

   request: static function (string $hostPort, int $testIndex) use ($Probe, $initialize, $Stamps): string {
      try {
         // @@ Until the store is full
         $Probe->answers['full'] = null;
         for ($attempt = 1; $attempt <= 600; $attempt++) {
            $Init = Client::call($hostPort, $testIndex, $initialize);
            if ($Init['code'] !== 200) {
               $Probe->answers['full'] = [$attempt, $Init['code'], $Init['json']['error']['message'] ?? null];
               $Probe->answers['stamp'] = $Stamps->check('sweep');
               break;
            }
         }

         // @ Every stored session expires (TTL 2) while the stamp of the
         //   refusal's sweep (5 s) still lives: no sweep, still refused
         usleep(3_200_000);
         $Held = Client::call($hostPort, $testIndex, $initialize);
         $Probe->answers['held'] = $Held['code'];

         // @ The stamp lapses; the next initialize sweeps and finds room
         usleep(3_000_000);
         $Again = Client::call($hostPort, $testIndex, $initialize);
         $Probe->answers['again'] = [$Again['code'], isSet($Again['headers']['mcp-session-id'])];
      }
      catch (Throwable $Throwable) {
         $Probe->error = "{$Throwable->getMessage()}";
      }

      return "GET /health HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: static function (Request $Request, Response $Response, Router $Router) use ($Transport): Generator {
      yield $Router->route('/mcp', static function (Request $Request, Response $Response) use ($Transport): Response {
         return $Transport($Request, $Response);
      }, [GET, POST, DELETE]);
   },

   test: new Assertions(Case: function (string $response) use ($Probe): Generator {
      $A = $Probe->answers;
      $evidence = json_encode(['error' => $Probe->error, 'answers' => $A]);

      yield new Assertion(
         description: 'a full store refuses a new session with 503',
         fallback: "Failed — {$evidence}"
      )
         ->expect([$A['full'][1] ?? null, $A['full'][2] ?? null])
         ->to->be([503, 'Service Unavailable: the session store cannot hold a new session'])
         ->assert();

      yield new Assertion(
         description: 'the refusal stamped the sweep of its store: no worker sweeps it again for 5 s',
         fallback: "Failed — {$evidence}"
      )
         ->expect($A['stamp'] ?? null)
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'while the stamp lives, a store of expired sessions is not swept: still 503',
         fallback: "Failed — {$evidence}"
      )
         ->expect($A['held'] ?? null)
         ->to->be(503)
         ->assert();

      yield new Assertion(
         description: 'once the stored sessions expire, a new initialize is served again',
         fallback: "Failed — {$evidence}"
      )
         ->expect($A['again'] ?? null)
         ->to->be([200, true])
         ->assert();
   })
);
