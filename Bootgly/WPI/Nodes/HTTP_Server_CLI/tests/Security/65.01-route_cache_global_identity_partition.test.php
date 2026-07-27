<?php

use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\API\Workables\Server\Middleware;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Security PoC H1 — the route response cache must not serve one admitted
 * principal's response to a different admitted principal.
 *
 * C2 moved cache replay AFTER global admission, so a second principal IS
 * authenticated before the hit. But `Cache::compose()` keys only on method,
 * authority, URI, proxy fields and optional `Accept-Language` — never on the
 * admitted identity — and `Router` treats only route/group middleware as
 * cache-disqualifying, so a route authenticated purely by GLOBAL middleware
 * still gets a TTL stamp. Alice primes the entry; Bob is admitted as himself
 * and then replays Alice's raw wire without his handler ever running.
 *
 * Only `Cookie` and `Authorization` have dedicated replay guards, so a custom
 * credential such as `X-API-Key` reaches this state.
 *
 * Controls: Alice must keep seeing Alice (so a fix cannot pass by serving
 * everyone a cold response for the wrong principal), and the invalid/missing
 * credential denials from case 54.02 must still hold.
 *
 * Note the assertions deliberately do NOT require a cache HIT — both valid
 * remediations (partitioning the key by principal, or refusing to cache
 * globally-authenticated routes) satisfy this case.
 */
$handlerRuns = 0;
$presetHandlerRuns = 0;

$Auth = new class implements Middleware {
   public int $runs = 0;
   public int $presets = 0;

   public function process (object $Request, object $Response, Closure $Next): object
   {
      /** @var Request $Request */
      /** @var Response $Response */
      if (str_starts_with($Request->URI, '/h1/') === false) {
         return $Next($Request, $Response);
      }

      $this->runs++;

      // ! Two DIFFERENT valid principals — this is what case 54.02 never
      //   exercised: it only ever sent one valid credential plus denials.
      // ! The last two pairs are the collision probes: a principal whose own
      //   text reproduces what another builds from its claims, and an integer
      //   identity against the string of the same digits.
      [$identity, $claims] = match ($Request->Header->get('X-API-Key')) {
         'alice-key' => ['alice', []],
         'bob-key' => ['bob', []],
         'merged-key' => ['alice|c:{"tier":"admin"}', []],
         'claimed-key' => ['alice', ['tier' => 'admin']],
         'int-key' => [1, []],
         'string-key' => ['1', []],
         // ! Nested TYPE collision: the bag serializer erased it, so an integer
         //   claim and a float claim composed one key. Strict application
         //   policy can distinguish `1` from `1.0`; the cache could not.
         'claim-int-key' => ['carol', ['level' => 1]],
         'claim-float-key' => ['carol', ['level' => 1.0]],
         'claim-true-key' => ['carol', ['level' => true]],
         // ! Top-level float identities must preserve all significant bits too.
         'identity-float-one-key' => [1.0, []],
         'identity-float-next-key' => [1.0000000000000002, []],
         'preset-key' => ['preset-user', []],
         default => [null, []],
      };

      if ($identity === null) {
         return $Response(code: 401, body: 'H1-DENIED');
      }

      $Request->identity = $identity;
      $Request->claims = $claims;

      $Result = $Next($Request, $Response);

      // ! `preset` affects serialization but is intentionally persistent and
      //   is not present in Encoder_::capture(). If this post-$Next mutation is
      //   not detected, the priming value is stored and raw-wire replay drops
      //   the current request's replacement. The cleanup request prevents this
      //   validation fixture from leaking its preset into later suite cases.
      if ($Result instanceof Response && $Request->URI === '/h1/preset') {
         $Result->Header->preset('X-H1-Preset', 'preset-' . ++$this->presets);
         return $Result;
      }
      if ($Result instanceof Response && $Request->URI === '/h1/preset-clean') {
         $Result->Header->preset('X-H1-Preset');
         return $Result;
      }

      // @ Post-$Next mutation: a per-request header stamped AFTER the handler
      //   (or after a cache replay) must reach the wire.
      if ($Result instanceof Response && $Result->code === 200) {
         $stamp = ++$this->runs;
         $Result->Header->set('X-H1-Nonce', "nonce-{$stamp}");
         // ! The current-request BODY mutation must win too. A replay has no
         //   handler body in the live Response, so the merge must restore the
         //   cached representation underneath this suffix without replacing
         //   the suffix with the priming request's value.
         $Result->Body->raw .= ";post={$stamp}";
      }

      return $Result;
   }
};

return new Specification(
   description: 'route cache must partition by the admitted global identity',
   Separator: new Separator(line: true),

   requests: [
      // 1 — Alice primes the entry.
      static fn (): string => "GET /h1/profile HTTP/1.1\r\n"
         . "Host: localhost\r\nX-API-Key: alice-key\r\n\r\n",
      // 2 — Bob is admitted as Bob and must NOT receive Alice's body.
      static fn (): string => "GET /h1/profile HTTP/1.1\r\n"
         . "Host: localhost\r\nX-API-Key: bob-key\r\n\r\n",
      // 3 — Alice again: her own content must still be correct.
      static fn (): string => "GET /h1/profile HTTP/1.1\r\n"
         . "Host: localhost\r\nX-API-Key: alice-key\r\n\r\n",
      // 4/5 — the 54.02 denials must survive the fix.
      static fn (): string => "GET /h1/profile HTTP/1.1\r\n"
         . "Host: localhost\r\nX-API-Key: invalid\r\n\r\n",
      static fn (): string => "GET /h1/profile HTTP/1.1\r\nHost: localhost\r\n\r\n",
      // 6/7 — separator/prefix collision: `alice|c:{...}` vs alice + claims.
      static fn (): string => "GET /h1/profile HTTP/1.1\r\n"
         . "Host: localhost\r\nX-API-Key: merged-key\r\n\r\n",
      static fn (): string => "GET /h1/profile HTTP/1.1\r\n"
         . "Host: localhost\r\nX-API-Key: claimed-key\r\n\r\n",
      // 8/9 — type collision: integer 1 vs string "1".
      static fn (): string => "GET /h1/profile HTTP/1.1\r\n"
         . "Host: localhost\r\nX-API-Key: int-key\r\n\r\n",
      static fn (): string => "GET /h1/profile HTTP/1.1\r\n"
         . "Host: localhost\r\nX-API-Key: string-key\r\n\r\n",
      // 10/11/12 — nested claim TYPE collision: 1 vs 1.0 vs true.
      static fn (): string => "GET /h1/profile HTTP/1.1\r\n"
         . "Host: localhost\r\nX-API-Key: claim-int-key\r\n\r\n",
      static fn (): string => "GET /h1/profile HTTP/1.1\r\n"
         . "Host: localhost\r\nX-API-Key: claim-float-key\r\n\r\n",
      static fn (): string => "GET /h1/profile HTTP/1.1\r\n"
         . "Host: localhost\r\nX-API-Key: claim-true-key\r\n\r\n",
      // 13/14 — two distinct finite float identities whose default PHP string
      // cast is the same `"1"`.
      static fn (): string => "GET /h1/profile HTTP/1.1\r\n"
         . "Host: localhost\r\nX-API-Key: identity-float-one-key\r\n\r\n",
      static fn (): string => "GET /h1/profile HTTP/1.1\r\n"
         . "Host: localhost\r\nX-API-Key: identity-float-next-key\r\n\r\n",
      // 15/16 — an actual replay whose only post-$Next mutation is a persistent
      // preset, a serialization surface omitted from capture().
      static fn (): string => "GET /h1/preset HTTP/1.1\r\n"
         . "Host: localhost\r\nX-API-Key: preset-key\r\n\r\n",
      static fn (): string => "GET /h1/preset HTTP/1.1\r\n"
         . "Host: localhost\r\nX-API-Key: preset-key\r\n\r\n",
      // 17 — restore worker-persistent state for later suite cases.
      static fn (): string => "GET /h1/preset-clean HTTP/1.1\r\n"
         . "Host: localhost\r\nX-API-Key: preset-key\r\n\r\n",
   ],

   middlewares: [$Auth],

   response: static function (Request $Request, Response $Response, Router $Router) use (
      &$handlerRuns,
      &$presetHandlerRuns
   ) {
      yield $Router->route('/h1/profile', function (
         Request $Request,
         Response $Response
      ) use (&$handlerRuns): Response {
         $handlerRuns++;

         // ! Principal-specific output — exactly the shape the finding is about.
         return $Response(
            // ! The claim TYPES are part of the principal state — and the very
            //   distinction `json_encode()` erased, so the body must carry them
            //   for the collision legs to be observable at all.
            body: 'H1-PROFILE:' . get_debug_type($Request->identity)
               . ':' . ((string) $Request->identity)
               . ':' . json_encode($Request->claims)
               . ';identity_exact=' . (
                  is_float($Request->identity)
                     ? sprintf('%.17G', $Request->identity)
                     : (string) $Request->identity
               )
               . ';types=' . implode(',', array_map(get_debug_type(...), $Request->claims))
               . ";handler={$handlerRuns}"
         );
      }, GET, cache: ['TTL' => 60]);

      yield $Router->route('/h1/preset', static function (
         Request $Request,
         Response $Response
      ) use (&$presetHandlerRuns): Response {
         return $Response(body: 'H1-PRESET:handler=' . ++$presetHandlerRuns);
      }, GET, cache: ['TTL' => 60]);

      yield $Router->route('/h1/preset-clean', static function (
         Request $Request,
         Response $Response
      ): Response {
         return $Response(body: 'H1-PRESET-CLEAN');
      }, GET);
   },

   test: static function (array $responses): bool|string {
      if (count($responses) !== 17) {
         return 'H1 fixture failed: expected seventeen live responses, got ' . count($responses) . '.';
      }

      [
         $alice, $bob, $aliceAgain, $invalid, $missing, $merged, $claimed, $integer, $string,
         $claimInteger, $claimFloat, $claimTrue, $identityFloatOne, $identityFloatNext,
         $presetPrime, $presetReplay, $presetCleanup
      ] = $responses;

      // ? Harness control — the primer must have reached the handler as Alice.
      if (! str_contains($alice, 'H1-PROFILE:string:alice:')) {
         return 'H1 harness control failed: the priming request did not produce Alice\'s '
            . 'profile: ' . json_encode(substr($alice, 0, 220));
      }

      // ? Retained 54.02 guarantees — admission still runs before replay.
      if (! str_contains($invalid, '401') || ! str_contains($invalid, 'H1-DENIED')) {
         return 'H1 control failed: an invalid credential was not denied: '
            . json_encode(substr($invalid, 0, 220));
      }
      if (! str_contains($missing, '401') || ! str_contains($missing, 'H1-DENIED')) {
         return 'H1 control failed: a missing credential was not denied: '
            . json_encode(substr($missing, 0, 220));
      }

      // @ The finding itself.
      if (str_contains($bob, 'H1-PROFILE:string:alice:')) {
         return 'CONFIRMED H1: a second VALID principal received the first principal\'s '
            . 'cached response. Bob authenticated as Bob and was served Alice\'s raw wire '
            . 'without his handler running — the route-cache key omits the admitted '
            . 'identity, and a route authenticated only by GLOBAL middleware still gets a '
            . 'TTL stamp. Bob received: ' . json_encode(substr($bob, 0, 220));
      }
      if (! str_contains($bob, 'H1-PROFILE:string:bob:')) {
         return 'H1: Bob received neither his own profile nor Alice\'s — the route no longer '
            . 'serves an admitted principal at all: ' . json_encode(substr($bob, 0, 220));
      }

      // @ Collisions — each pair must reach its OWN principal's body.
      $collisions = [];
      if (! str_contains($merged, 'alice|c:')) {
         $collisions[] = 'separator/prefix state received ' . json_encode(substr($merged, -90));
      }
      if (! str_contains($claimed, '{"tier":"admin"}')) {
         $collisions[] = 'claims state received ' . json_encode(substr($claimed, -90));
      }
      if (! str_contains($integer, 'H1-PROFILE:int:1:')) {
         $collisions[] = 'integer identity received ' . json_encode(substr($integer, -90));
      }
      if (! str_contains($string, 'H1-PROFILE:string:1:')) {
         $collisions[] = 'string identity received ' . json_encode(substr($string, -90));
      }
      // @ Nested claim types — `json_encode()` renders all three bags as
      //   `{"level":1}`, so only the `types=` segment separates them.
      foreach ([
         'types=int' => $claimInteger,
         'types=float' => $claimFloat,
         'types=bool' => $claimTrue,
      ] as $expected => $wire) {
         if (! str_contains($wire, (string) $expected)) {
            $collisions[] = "claim state `{$expected}` received "
               . json_encode(substr($wire, -110));
         }
      }
      if (! str_contains($identityFloatOne, 'identity_exact=1;')) {
         $collisions[] = 'float identity 1.0 received '
            . json_encode(substr($identityFloatOne, -130));
      }
      if (! str_contains($identityFloatNext, 'identity_exact=1.0000000000000002;')) {
         $collisions[] = 'the next representable float identity received '
            . json_encode(substr($identityFloatNext, -130));
      }
      if ($collisions !== []) {
         return 'CONFIRMED H1: distinct principal states shared one cache entry — '
            . implode('; ', $collisions)
            . '. The key concatenated unframed, type-erased markers, so one principal\'s '
            . 'state reproduced another\'s key.';
      }

      // @ Post-$Next mutation must reach the wire even on a replay.
      $Nonce = static function (string $wire): string {
         return preg_match('/^X-H1-Nonce: (.+)$/mi', $wire, $matches) === 1
            ? trim($matches[1])
            : '';
      };

      foreach (['alice' => $alice, 'aliceAgain' => $aliceAgain, 'bob' => $bob] as $name => $wire) {
         if ($Nonce($wire) === '') {
            return 'CONFIRMED H1: a per-request header stamped by global middleware AFTER '
               . "\$Next was dropped on {$name} — replay returned the stored wire and "
               . 'ignored the current response: ' . json_encode(substr($wire, 0, 200));
         }
      }

      // ? Alice's third request is the one that HITS her own entry, so it is
      //   the request whose post-$Next mutation a raw-wire replay can silence.
      //   A merely-present nonce proves nothing: the stored wire carries the
      //   PRIMING request's nonce and satisfies that check while the current
      //   one is lost. The value must be this request's.
      if ($Nonce($aliceAgain) === $Nonce($alice)) {
         return 'CONFIRMED H1: a replay served the STORED per-request header. Alice\'s second '
            . 'hit carried the priming request\'s nonce (' . json_encode($Nonce($alice))
            . '), so a per-request value stamped by global middleware after $Next — a nonce, '
            . 'a CSP or a correlation id — is silently replaced by the cached one.';
      }
      $Post = static function (string $wire): string {
         return preg_match('/;post=(\d+)/', $wire, $matches) === 1 ? $matches[1] : '';
      };
      if ($Post($alice) === '' || $Post($aliceAgain) === '') {
         return 'H1 body-mutation control failed: the cold or replayed response did not '
            . 'carry its post-$Next body marker.';
      }
      if ($Post($aliceAgain) === $Post($alice)) {
         return 'CONFIRMED H1: a replay served the STORED post-$Next body mutation. '
            . 'Alice\'s hit carried the priming marker (' . json_encode($Post($alice))
            . ') instead of the current request marker.';
      }
      // ? ...and restoring the current mutation must not cost the cached body.
      if (! str_contains($aliceAgain, 'H1-PROFILE:string:alice:')) {
         return 'CONFIRMED H1: preserving the post-$Next mutation dropped the cached '
            . 'representation — the handler was skipped for the replay, so the response '
            . 'reached the wire with no content: ' . json_encode(substr($aliceAgain, 0, 220));
      }

      // ? Alice must still get Alice after Bob's request.
      if (! str_contains($aliceAgain, 'H1-PROFILE:string:alice:')) {
         return 'H1 control failed: Alice stopped receiving her own profile after Bob\'s '
            . 'request: ' . json_encode(substr($aliceAgain, 0, 220));
      }

      // @ Exercise a real replay whose mutation is absent from capture().
      $Preset = static function (string $wire): string {
         return preg_match('/^X-H1-Preset: (.+)$/mi', $wire, $matches) === 1
            ? trim($matches[1])
            : '';
      };
      if (
         ! str_contains($presetPrime, 'H1-PRESET:handler=1')
         || ! str_contains($presetCleanup, 'H1-PRESET-CLEAN')
         || $Preset($presetPrime) === ''
         || $Preset($presetReplay) === ''
      ) {
         return 'H1 preset control failed: the priming, replay, or cleanup leg did not '
            . 'exercise the intended response path: '
            . json_encode([$presetPrime, $presetReplay, $presetCleanup]);
      }
      if (
         str_contains($presetReplay, 'H1-PRESET:handler=1')
         && $Preset($presetReplay) === $Preset($presetPrime)
      ) {
         return 'CONFIRMED H1: a real cache replay served the STORED post-$Next preset. '
            . 'The handler was skipped, but the second request received '
            . json_encode($Preset($presetReplay))
            . ' instead of its current per-request preset.';
      }
      if (
         ! str_contains($presetReplay, 'H1-PRESET:handler=1')
         && ! str_contains($presetReplay, 'H1-PRESET:handler=2')
      ) {
         return 'H1 preset control failed: the second request was neither a valid replay '
            . 'nor a safe cache miss: ' . json_encode($presetReplay);
      }

      return true;
   },
);
