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
 *
 * A full store is full in bytes, not in sessions: a refused session leaves
 * room for at most one smaller one (one whose index bucket already exists),
 * so the spec asks in bursts — a store swept would serve the whole burst.
 * Both clocks are whole seconds, so nothing is timed from a sleep: a cycle
 * waits for the stamp to lapse, fills the store (its first refusal takes the
 * sweep turn), waits until every session it wrote has expired, and asks only
 * while that stamp still has a second to live — another cycle when a slow
 * host misses the window.
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
// ! Calls per burst: a swept store serves them all, a full one at most one
$burst = 10;

return new Test(
   Separator: new Separator(line: ''),

   request: static function (string $hostPort, int $testIndex) use ($Probe, $initialize, $Stamps, $burst): string {
      // # Fill the store until an `initialize` is refused: [attempts, code, message, second, worker]
      $Fill = static function () use ($hostPort, $testIndex, $initialize): null|array {
         // @@
         for ($attempt = 1; $attempt <= 600; $attempt++) {
            $Init = Client::call($hostPort, $testIndex, $initialize);
            if ($Init['code'] !== 200) {
               // :
               return [$attempt, $Init['code'], $Init['json']['error']['message'] ?? null, time(), $Init['headers']['x-worker'] ?? null];
            }
         }

         // :
         return null;
      };
      // # Wait until $Ready answers true, for up to $seconds: the second it did, or null
      $Await = static function (Closure $Ready, float $seconds): null|int {
         $deadline = microtime(true) + $seconds;
         // @@
         while (microtime(true) < $deadline) {
            if ($Ready() === true) {
               // :
               return time();
            }
            usleep(20_000);
         }

         // :
         return null;
      };

      // # Ask $burst `initialize` calls in a row: [served, codes, first second, last second]
      $Burst = static function () use ($hostPort, $testIndex, $initialize, $burst): array {
         $served = 0;
         $codes = [];
         $first = time();
         // @@
         for ($call = 1; $call <= $burst; $call++) {
            $Init = Client::call($hostPort, $testIndex, $initialize);
            $codes[] = $Init['code'];
            if ($Init['code'] === 200 && isSet($Init['headers']['mcp-session-id']) === true) {
               $served++;
            }
         }

         // :
         return [$served, implode(',', $codes), $first, time()];
      };

      try {
         // @@ A cycle: the stamp lapses, the store fills (its first refusal
         //    takes the sweep turn), every session expires — and a burst comes
         //    while that stamp still has a second to live
         $Probe->answers['cycles'] = [];
         for ($cycle = 1; $cycle <= 3; $cycle++) {
            $Record = [];
            $Probe->answers['cycles'][] = &$Record;

            // @ No sweep turn is taken: the next refusal takes it
            $Record['lapsed'] = $Await(static fn (): bool => $Stamps->check('sweep') === false, 7.0);

            // @ Full: refused, and its sweep turn is stamped
            $start = time();
            $Full = $Fill();
            $Record['full'] = $Full;
            $Record['stamp'] = $Stamps->check('sweep');

            // @ Every session written so far expires
            $refused = $Full[3] ?? time();
            $Await(static fn (): bool => time() >= $refused + 2, 3.0);

            // ? The stamp, written at `$start` or later, lives through
            //   `$start + 4`: one second of margin for the burst in flight
            $Record['window'] = [time() <= $start + 3, $start, time()];
            if ($Record['window'][0] === false) {
               unset($Record);
               continue;
            }

            // @ Every stored session expired, the stamp lives: no sweep
            $Record['pre'] = $Stamps->check('sweep');
            $Record['held'] = $Burst();
            $Record['post'] = $Stamps->check('sweep');

            // @ The stamp lapses: the next refusal sweeps, and the burst is served
            $Record['relapsed'] = $Await(static fn (): bool => $Stamps->check('sweep') === false, 7.0);
            $Record['again'] = $Burst();
            $Record['restamped'] = $Stamps->check('sweep');
            unset($Record);
            break;
         }
      }
      catch (Throwable $Throwable) {
         $Probe->error = "{$Throwable->getMessage()}";
      }

      return "GET /health HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: static function (Request $Request, Response $Response, Router $Router) use ($Transport): Generator {
      yield $Router->route('/mcp', static function (Request $Request, Response $Response) use ($Transport): Response {
         // ! Which worker answered — evidence for a failure
         $Response->Header->set('X-Worker', (string) getmypid());

         return $Transport($Request, $Response);
      }, [GET, POST, DELETE]);
   },

   test: new Assertions(Case: function (string $response) use ($Probe, $burst): Generator {
      $A = $Probe->answers;
      $evidence = json_encode(['error' => $Probe->error, 'answers' => $A]);
      // ! The cycle that reached its window (or the last one tried)
      $Cycles = $A['cycles'] ?? [];
      $Last = $Cycles === [] ? [] : $Cycles[array_key_last($Cycles)];

      yield new Assertion(
         description: 'a full store refuses a new session with 503',
         fallback: "Failed — {$evidence}"
      )
         ->expect([$Last['full'][1] ?? null, $Last['full'][2] ?? null])
         ->to->be([503, 'Service Unavailable: the session store cannot hold a new session'])
         ->assert();

      yield new Assertion(
         description: 'the refusal stamped the sweep of its store: no worker sweeps it again for 5 s',
         fallback: "Failed — {$evidence}"
      )
         ->expect($Last['stamp'] ?? null)
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'the spec reaches the window where every session expired and the stamp still lives',
         fallback: "Failed — {$evidence}"
      )
         ->expect([$Last['window'][0] ?? null, $Last['pre'] ?? null, $Last['post'] ?? null])
         ->to->be([true, true, true])
         ->assert();

      yield new Assertion(
         description: 'while the stamp lives, a store of expired sessions is not swept: a burst is refused but for the one session the leftover bytes may hold',
         fallback: "Failed — {$evidence}"
      )
         ->expect(($Last['held'][0] ?? $burst) <= 1)
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'once the stamp lapses, a refusal sweeps the expired sessions: the whole burst is served, and the sweep turn is stamped again',
         fallback: "Failed — {$evidence}"
      )
         ->expect([$Last['again'][0] ?? null, $Last['restamped'] ?? null])
         ->to->be([$burst, true])
         ->assert();
   })
);
