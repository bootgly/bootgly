<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use const Bootgly\WPI;

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Buffers;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Buffers\Shares;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authenticating;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authentication\Bearer;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Deferred\Routes;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Idle\Client;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * Security PoC — what a bogus credential costs the deferring mode of
 * `Authentication`.
 *
 * The synchronous pre-check (`Guard::detect()`) answers a request without
 * credentials at once, but it cannot tell a bogus token from a good one: a
 * request that carries any `Authorization: Bearer …` defers, and its guard
 * runs on a snapshot charged to the worker budget's Inbound share. An
 * attacker who sends many bogus tokens to a lookup that hangs must buy no
 * more than one snapshot per request, for no longer than the mode's
 * `timeout`.
 *
 * 16 peers at once send one bogus token each to a route behind the mode
 * (`timeout: 0.5`) whose Resolver parks 30 s. While they park, the Inbound
 * ledger holds exactly 16 snapshots (one capture each, at most 128 KiB per
 * small GET); every peer is answered 503 at about the bound — not after
 * 30 s — and the ledger returns to its base. 16 peers without credentials,
 * at once, are answered the 401 invitation without the ledger moving and
 * without reaching the Resolver.
 */
$Probe = new class {
   /** @var array<string,mixed> */
   public array $answers = [];
   public string $error = '';
};
$Seen = new class {
   /** @var array<int,true> */
   public array $snapshots = [];
   public int $calls = 0;
};
$Mode = new Authentication(new Authenticating(new Bearer(
   Resolver: static function (string $token, Request $Request) use ($Seen): mixed {
      // @ A lookup that hangs: only the mode's bound ends it
      $Seen->calls++;
      $Seen->snapshots[spl_object_id($Request)] = true;
      $WPI = WPI;
      Routes::park($WPI->Response, 30.0);

      return null;
   }
)), deferred: true, timeout: 0.5);
// ! The worker budget's Inbound share: unfinished bodies and parked snapshots
$Inbound = static function (): int {
   $held = new ReflectionProperty(Buffers::class, 'held')->getValue();

   return is_array($held) && is_int($held[Shares::Inbound->value] ?? null)
      ? $held[Shares::Inbound->value]
      : -1;
};

return new Test(
   Separator: new Separator(line: true),

   request: static function (string $hostPort, int $testIndex) use ($Probe): string {
      $ledger = static function () use ($hostPort, $testIndex): array {
         $Socket = Client::open($hostPort);
         $answer = Client::send($Socket, Client::request('/am-sec/ledger', $testIndex), 4.0);
         Client::close($Socket);
         $ledger = json_decode($answer['body'], true);

         return is_array($ledger) ? $ledger : [];
      };
      $flight = static function (string $authorization) use ($hostPort, $testIndex, $ledger): array {
         // @ Every peer sends at once
         $Sockets = [];
         for ($i = 0; $i < 16; $i++) {
            $headers = $authorization === '' ? [] : ['Authorization' => "{$authorization}-{$i}"];
            $Socket = Client::open($hostPort);
            fwrite($Socket, Client::request('/am-sec/who', $testIndex, $headers));
            $Sockets[] = $Socket;
         }
         // @ While they park (or are already answered)
         usleep(250_000);
         $during = $ledger();
         // @ Every answer
         $answers = [];
         foreach ($Sockets as $Socket) {
            $answers[] = Client::send($Socket, '', 4.0);
            Client::close($Socket);
         }

         return [$during, $answers];
      };
      try {
         $Probe->answers['base'] = $ledger();
         $started = microtime(true);
         [$Probe->answers['bogus-during'], $bogus] = $flight('Bearer bogus');
         $Probe->answers['bogus-elapsed'] = microtime(true) - $started;
         $Probe->answers['bogus-codes'] = array_count_values(array_map(static fn (array $answer): int => $answer['code'], $bogus));
         $Probe->answers['bogus-after'] = $ledger();
         [$Probe->answers['none-during'], $none] = $flight('');
         $Probe->answers['none-codes'] = array_count_values(array_map(static fn (array $answer): int => $answer['code'], $none));
         $Probe->answers['none-after'] = $ledger();
      }
      catch (Throwable $Throwable) {
         $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /deferred/ping HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: static function (Request $Request, Response $Response, Router $Router) use ($Mode, $Inbound, $Seen): Generator {
      yield $Router->route('/am-sec/who', static function (Request $Request, Response $Response) {
         return $Response(body: 'unreachable');
      }, GET, middlewares: [$Mode]);
      yield $Router->route('/am-sec/ledger', static function (Request $Request, Response $Response) use ($Inbound, $Seen) {
         $Response->JSON->send([
            'inbound' => $Inbound(),
            'calls' => $Seen->calls,
            'snapshots' => count($Seen->snapshots)
         ]);

         return $Response;
      }, GET);
      yield from Routes::mount($Router);
   },

   test: new Assertions(Case: function (string $response) use ($Probe): Generator {
      $A = $Probe->answers;
      $evidence = json_encode(['error' => $Probe->error, 'answers' => $A]);
      $base = $A['base']['inbound'] ?? -1;
      $during = $A['bogus-during']['inbound'] ?? -1;
      $grown = $during - $base;

      yield new Assertion(
         description: 'Each bogus token defers once: 16 guard runs on 16 snapshots',
         fallback: "Unexpected guard runs: {$evidence}"
      )
         ->expect([$A['bogus-during']['calls'] ?? 0, $A['bogus-during']['snapshots'] ?? 0])
         ->to->be([16, 16])
         ->assert();

      yield new Assertion(
         description: 'The parked snapshots are charged to the Inbound share, at most 128 KiB each',
         fallback: "The parked bogus requests were not charged as bounded snapshots: {$evidence}"
      )
         ->expect([$base >= 0, $grown > 0, $grown <= 16 * 131072])
         ->to->be([true, true, true])
         ->assert();

      yield new Assertion(
         description: 'Every bogus token is answered 503 at about the bound, never after the hanging lookup',
         fallback: "A bogus token outlived the bound: {$evidence}"
      )
         ->expect([$A['bogus-codes'] ?? [], ($A['bogus-elapsed'] ?? 99.0) < 2.5])
         ->to->be([[503 => 16], true])
         ->assert();

      yield new Assertion(
         description: 'Once answered, the ledger returns to its base',
         fallback: "The bogus requests left charges behind: {$evidence}"
      )
         ->expect($A['bogus-after']['inbound'] ?? -1)
         ->to->be($base)
         ->assert();

      yield new Assertion(
         description: 'Requests without credentials get the 401 at once: no snapshot, no guard run',
         fallback: "A credential-less request was deferred: {$evidence}"
      )
         ->expect([
            $A['none-codes'] ?? [],
            $A['none-during']['inbound'] ?? -1,
            $A['none-after']['calls'] ?? 0
         ])
         ->to->be([[401 => 16], $base, 16])
         ->assert();
   })
);
