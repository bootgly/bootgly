<?php

use function sys_get_temp_dir;
use function uniqid;

use Generator;

use Bootgly\ABI\Resources\Cache;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Doubles\Fake\Clock;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\RateLimit;


/**
 * PoC — Audit F-3: RateLimit must key on the immutable transport peer, not the
 * proxy-mutable `$Request->address`.
 *
 * `TrustedProxy` overwrites `$Request->address` from `X-Forwarded-For` when the
 * connection arrives from a trusted proxy (the default trust list includes
 * localhost). A client co-located with / behind that proxy can therefore rotate
 * `X-Forwarded-For` per request; if RateLimit keyed on `$address`, every spoofed
 * value would open its own fixed-window bucket and the limit would be unbounded.
 *
 * Fix: RateLimit keys on `$Request->peer` (the immutable TCP transport IP) by
 * default. `trustForwarded: true` opts back into `$address` for deployments that
 * genuinely trust the forwarded chain.
 *
 * Before the fix, RateLimit had no `trustForwarded` parameter and keyed on
 * `$address`, so Scenario 1 below would never reach 429 — exactly the bypass.
 */
return new Specification(
   description: 'RateLimit keys on the immutable transport peer (X-Forwarded-For rotation must not evade it)',
   test: new Assertions(Case: function (): Generator {
      $createMocks = require __DIR__ . '/0.mock.php';
      $passthrough = function (object $Request, object $Response): object {
         return $Response;
      };
      $Clock = new Clock(100);

      $makeCache = function () use ($Clock): Cache {
         $dir = sys_get_temp_dir() . '/bootgly-ratelimit-f3-' . uniqid();
         $Cache = new Cache([
            'driver' => 'file',
            'path' => $dir,
            'prefix' => 'ratelimit:',
            'clock' => static fn (): int => (int) $Clock->now,
         ]);
         $Cache->clear();
         return $Cache;
      };

      // # Scenario 1 — default (trustForwarded = false): key on $peer.
      //   Constant peer, rotating spoofed address: the cap MUST still trigger.
      $Cache = $makeCache();
      try {
         $RateLimit = new RateLimit(
            limit: 3,
            window: 60,
            clock: fn (): float => $Clock->now,
            Cache: $Cache
         );

         $codes = [];
         for ($i = 1; $i <= 5; $i++) {
            [$Request, $Response] = $createMocks(requestProps: [
               'peer'    => '203.0.113.7', // immutable transport peer (constant)
               'address' => "10.0.0.{$i}",  // rotated per request (spoofed XFF result)
            ]);
            $codes[] = $RateLimit->process($Request, $Response, $passthrough)->code;
         }

         yield new Assertion(description: 'First request passes (200)')
            ->expect($codes[0])->to->be(200)->assert();
         yield new Assertion(description: 'Rotating spoofed address must NOT open new buckets — 4th request is 429')
            ->expect($codes[3])->to->be(429)->assert();
         yield new Assertion(description: '5th rotated-address request is also 429 (still one peer bucket)')
            ->expect($codes[4])->to->be(429)->assert();
      }
      finally {
         $Cache->clear();
      }

      // # Scenario 2 — trustForwarded = true: key on $address (explicit opt-in).
      //   Rotating address ⇒ independent buckets ⇒ not capped here, proving the
      //   opt-in path keys on the application IP as documented.
      $Cache = $makeCache();
      try {
         $RateLimit = new RateLimit(
            limit: 3,
            window: 60,
            trustForwarded: true,
            clock: fn (): float => $Clock->now,
            Cache: $Cache
         );

         $codes = [];
         for ($i = 1; $i <= 5; $i++) {
            [$Request, $Response] = $createMocks(requestProps: [
               'peer'    => '203.0.113.7',
               'address' => "10.0.0.{$i}", // each a distinct bucket when trustForwarded
            ]);
            $codes[] = $RateLimit->process($Request, $Response, $passthrough)->code;
         }

         yield new Assertion(description: 'trustForwarded keys on $address — 4th distinct IP still passes (200)')
            ->expect($codes[3])->to->be(200)->assert();
         yield new Assertion(description: 'trustForwarded keys on $address — 5th distinct IP still passes (200)')
            ->expect($codes[4])->to->be(200)->assert();
      }
      finally {
         $Cache->clear();
      }
   })
);
