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
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\RateLimit\Algorithms;


/**
 * PoC — Audit F-4: RateLimit was trivially bypassable.
 *
 *   (a) IPv6 /128 keying — a routed /64 yields 2^64 distinct keys, so the
 *       limiter never fires. Fixed: keys are aggregated to /64 (configurable).
 *   (b) Fixed-window 2x burst across a boundary. Fixed: weighted sliding window.
 *   (c) No aggregate cap. Fixed: optional cross-worker global ceiling.
 *   (d) Key hard-wired to the IP. Fixed: pluggable key resolver closure.
 *
 * Each scenario drives the real middleware deterministically (mock Request +
 * file-backed Cache + fake Clock). Before the fix none of these parameters
 * existed and the limiter keyed on the full IP, so scenarios A/C/D below would
 * not trigger — exactly the bypasses.
 */
return new Specification(
   description: 'RateLimit: IPv6 /64 aggregation, pluggable key, global ceiling, sliding-window burst control',
   test: new Assertions(Case: function (): Generator {
      $createMocks = require __DIR__ . '/0.mock.php';
      $passthrough = function (object $Request, object $Response): object {
         return $Response;
      };
      $Clock = new Clock(100);
      $makeCache = function () use ($Clock): Cache {
         $dir = sys_get_temp_dir() . '/bootgly-ratelimit-f4-' . uniqid();
         $Cache = new Cache([
            'driver' => 'file',
            'path' => $dir,
            'prefix' => 'ratelimit:',
            'clock' => static fn (): int => (int) $Clock->now,
         ]);
         $Cache->clear();
         return $Cache;
      };

      // # (a) IPv6 /64 aggregation — distinct /128s in one /64 share a bucket.
      $Cache = $makeCache();
      try {
         $RateLimit = new RateLimit(
            limit: 3, window: 60, algorithm: Algorithms::Fixed,
            clock: fn (): float => $Clock->now, Cache: $Cache
         );
         $codes = [];
         foreach (['2001:db8:1:2::1', '2001:db8:1:2::2', '2001:db8:1:2::3', '2001:db8:1:2::4'] as $ip) {
            [$Request, $Response] = $createMocks(requestProps: ['peer' => $ip]);
            $codes[] = $RateLimit->process($Request, $Response, $passthrough)->code;
         }
         yield new Assertion(description: 'Third /128 in the same /64 still within limit (shared bucket)')
            ->expect($codes[2])->to->be(200)->assert();
         yield new Assertion(description: 'Fourth /128 in the same /64 is 429 — a routed /64 cannot mint fresh keys')
            ->expect($codes[3])->to->be(429)->assert();

         [$Request, $Response] = $createMocks(requestProps: ['peer' => '2001:db8:1:3::9']);
         yield new Assertion(description: 'A different /64 is a separate bucket (200)')
            ->expect($RateLimit->process($Request, $Response, $passthrough)->code)->to->be(200)->assert();
      }
      finally {
         $Cache->clear();
      }

      // # (d) Pluggable key — group by API key regardless of source IP.
      $Cache = $makeCache();
      try {
         $RateLimit = new RateLimit(
            limit: 2, window: 60, algorithm: Algorithms::Fixed,
            key: fn (object $Request): string => 'apikey:' . $Request->Header->get('X-Api-Key'),
            clock: fn (): float => $Clock->now, Cache: $Cache
         );
         $codes = [];
         for ($i = 1; $i <= 3; $i++) {
            [$Request, $Response] = $createMocks(
               requestHeaders: ['X-Api-Key' => 'secret-k'],
               requestProps: ['peer' => "10.0.0.{$i}"] // different IP each time
            );
            $codes[] = $RateLimit->process($Request, $Response, $passthrough)->code;
         }
         yield new Assertion(description: 'Second request on the same API key is within limit (200)')
            ->expect($codes[1])->to->be(200)->assert();
         yield new Assertion(description: 'Third request on the same API key is 429 despite rotating IPs (keyed on the API key)')
            ->expect($codes[2])->to->be(429)->assert();
      }
      finally {
         $Cache->clear();
      }

      // # (c) Global ceiling — aggregate cap across distinct per-key buckets.
      $Cache = $makeCache();
      try {
         $RateLimit = new RateLimit(
            limit: 100, window: 60, globalLimit: 3, algorithm: Algorithms::Fixed,
            clock: fn (): float => $Clock->now, Cache: $Cache
         );
         $codes = [];
         for ($i = 1; $i <= 4; $i++) {
            [$Request, $Response] = $createMocks(requestProps: ['peer' => "198.51.100.{$i}"]);
            $codes[] = $RateLimit->process($Request, $Response, $passthrough)->code;
         }
         yield new Assertion(description: 'Third distinct IP still under the global ceiling (200)')
            ->expect($codes[2])->to->be(200)->assert();
         yield new Assertion(description: 'Fourth distinct IP hits the global ceiling (429) though no per-IP limit was reached')
            ->expect($codes[3])->to->be(429)->assert();
      }
      finally {
         $Cache->clear();
      }

      // # (b) Sliding window — no fresh burst at the window boundary.
      $Clock = new Clock(100);
      $Cache = $makeCache();
      try {
         $RateLimit = new RateLimit(
            limit: 4, window: 60, algorithm: Algorithms::Sliding,
            clock: fn (): float => $Clock->now, Cache: $Cache
         );
         $codes = [];
         for ($i = 1; $i <= 5; $i++) {
            [$Request, $Response] = $createMocks(requestProps: ['peer' => '192.0.2.50']);
            $codes[] = $RateLimit->process($Request, $Response, $passthrough)->code;
         }
         yield new Assertion(description: 'Fourth request within the window is allowed (200)')
            ->expect($codes[3])->to->be(200)->assert();
         yield new Assertion(description: 'Fifth request within the window is 429')
            ->expect($codes[4])->to->be(429)->assert();

         // Cross the window boundary: a fixed window would reset to a fresh
         // `limit`; the sliding window still counts the full previous window.
         $Clock->advance(20); // now = 120 → start of the next window
         [$Request, $Response] = $createMocks(requestProps: ['peer' => '192.0.2.50']);
         yield new Assertion(description: 'First request in the next window is still 429 — no boundary burst (sliding)')
            ->expect($RateLimit->process($Request, $Response, $passthrough)->code)->to->be(429)->assert();

         // As the previous window decays out of view, requests recover.
         $Clock->advance(40); // now = 160 → previous window 2/3 decayed
         [$Request, $Response] = $createMocks(requestProps: ['peer' => '192.0.2.50']);
         yield new Assertion(description: 'Once the previous window decays, requests are admitted again (200)')
            ->expect($RateLimit->process($Request, $Response, $passthrough)->code)->to->be(200)->assert();
      }
      finally {
         $Cache->clear();
      }
   })
);
