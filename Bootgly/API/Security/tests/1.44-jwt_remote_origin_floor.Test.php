<?php

namespace Bootgly\API\Security\Tests\JWTRemoteOriginFloor;


use const JSON_THROW_ON_ERROR;
use function array_fill;
use function assert;
use function base64_encode;
use function bin2hex;
use function fmod;
use function function_exists;
use function get_debug_type;
use function hrtime;
use function is_dir;
use function json_encode;
use function microtime;
use function random_bytes;
use function rmdir;
use function rtrim;
use function strtr;
use function sys_get_temp_dir;
use function time;
use function unlink;
use function usleep;
use Closure;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Security\JWT;
use Bootgly\API\Security\JWT\Failures;
use Bootgly\API\Security\JWT\Key;
use Bootgly\API\Security\JWT\KeySet;
use Bootgly\API\Security\JWT\Remote;
use Bootgly\API\Security\JWT\Remote\Response;
use Bootgly\API\Security\JWT\Vault;


/**
 * Regression (API-SEC-1) — the remote JWKS resolver bounds its origin fetches
 * per process: a confirmed set is held for one window (`cooldown`, or a shorter
 * positive `$TTL`) and a failure is replayed for one `cooldown`. Key resolution
 * runs before signature verification, so before this floor every
 * unauthenticated token with a well-formed header forced one blocking fetch
 * whenever the IdP forbade caching (`no-store`, `no-cache`, `max-age=0`,
 * `Age >= max-age`), the operator set `ttl: 0`, or the origin was failing.
 */

return new Test(
   description: 'JWT: bound remote JWKS origin fetches with a per-process floor',
   skip: function_exists('openssl_pkey_get_details') === false
      || function_exists('openssl_pkey_get_private') === false,
   test: function () {
      /** @var array{first:array{body:string,jwk:array{kid:string},private:string,public:string},second:array{body:string,jwk:array{kid:string},private:string,public:string}} $fixtures */
      $fixtures = require __DIR__ . '/fixtures/jwt_rs256.php';
      $first = $fixtures['first'];
      $second = $fixtures['second'];
      $both = json_encode(['keys' => [$first['jwk'], $second['jwk']]], JSON_THROW_ON_ERROR);
      $outcomes = [];
      $Record = static function (bool $passed, string $description, string $diagnostic = '') use (&$outcomes): void {
         $outcomes[] = [
            'passed' => $passed,
            'description' => $passed ? $description : "API-SEC-1: {$description}. {$diagnostic}",
         ];
      };

      // ! Tokens — one valid per key, and forged ones: a well-formed header
      //   with a garbage signature is all an attacker needs
      $pack = static fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
      $forge = static fn (array $header): string => $pack(json_encode($header, JSON_THROW_ON_ERROR))
         . '.' . $pack(json_encode(['sub' => 'forged', 'exp' => time() + 300], JSON_THROW_ON_ERROR))
         . '.' . $pack('garbage-signature');
      $sign = static function (array $material) {
         $Signer = new JWT($material['private'], 'RS256');
         $Signer->select(new Key($material['private'], 'RS256', $material['jwk']['kid']));

         return $Signer->sign(['sub' => 'user', 'exp' => time() + 300]);
      };
      $valid = $sign($first);
      $rotated = $sign($second);
      $forged = $forge(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $first['jwk']['kid']]);
      $unknown = $forge(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'not-published']);
      $symmetric = $forge(['alg' => 'HS256', 'typ' => 'JWT', 'kid' => $first['jwk']['kid']]);

      // ! A counting origin whose answer the probe swaps between legs
      $origin = static function (int &$calls, Closure &$Answer): Closure {
         return static function () use (&$calls, &$Answer): Response|string {
            $calls++;
            return $Answer();
         };
      };
      $serve = static fn (string $body, array $headers = []): Closure =>
         static fn (): Response => new Response(200, $body, $headers);
      $down = static fn (): Closure => static function (): never {
         throw new RuntimeException('origin unreachable');
      };
      $pause = static function (int $from, int $nanoseconds): void {
         while (hrtime(true) - $from < $nanoseconds) {
            usleep(20_000);
         }
      };
      $inspect = static function (JWT $Verifier, string $token, int $times): array {
         $results = [];
         for ($i = 0; $i < $times; $i++) {
            $Verification = $Verifier->inspect($token);
            $results[] = $Verification->valid ? 'valid' : ($Verification->failure->name ?? 'none');
         }

         return $results;
      };

      // @@ Every zero-TTL outcome: 5 forged + 5 valid tokens cost ONE origin fetch
      $shapes = [
         'no-store' => [['Cache-Control: no-store'], 3600],
         'no-cache' => [['Cache-Control: no-cache'], 3600],
         'max-age=0' => [['Cache-Control: max-age=0'], 3600],
         'Age >= max-age' => [['Cache-Control: max-age=60', 'Age: 60'], 3600],
         'operator ttl 0' => [['Cache-Control: max-age=60'], 0],
      ];
      foreach ($shapes as $label => [$headers, $ttl]) {
         try {
            $calls = 0;
            $Answer = $serve($first['body'], $headers);
            $Remote = new Remote("https://issuer.example/floor/{$label}", $origin($calls, $Answer), ttl: $ttl);
            $Verifier = new JWT($Remote, 'RS256');
            $results = [...$inspect($Verifier, $forged, 5), ...$inspect($Verifier, $valid, 5)];
            $expected = [...array_fill(0, 5, 'Signature'), ...array_fill(0, 5, 'valid')];
            $Record(
               $calls === 1 && $results === $expected && $Remote->expires === 0,
               "{$label}: 5 forged + 5 valid tokens cost one origin fetch and valid tokens stay valid",
               "calls={$calls}; results=" . json_encode($results) . "; expires={$Remote->expires}"
            );
         }
         catch (Throwable $Exception) {
            $Record(false, "{$label}: one origin fetch", 'threw ' . $Exception::class . ': ' . $Exception->getMessage());
         }
      }

      // @ Control: `cooldown: 0` disables the floor — one fetch per verification
      $calls = 0;
      $Answer = $serve($first['body'], ['Cache-Control: no-store']);
      $Remote = new Remote('https://issuer.example/floor/off', $origin($calls, $Answer), cooldown: 0);
      $inspect(new JWT($Remote, 'RS256'), $forged, 5);
      $Record($calls === 5, 'cooldown 0 disables the floor', "calls={$calls}; expected 5");

      // @@ Failures are replayed for the cooldown — one fetch, the same category
      $failures = [
         'Network' => $down(),
         'Status' => static fn (): Response => new Response(500, 'unavailable'),
         'JSON' => static fn (): Response => new Response(200, '{'),
      ];
      foreach ($failures as $category => $Failing) {
         $calls = 0;
         $Answer = $Failing;
         $Remote = new Remote("https://issuer.example/floor/failure/{$category}", $origin($calls, $Answer));
         $results = $inspect(new JWT($Remote, 'RS256'), $valid, 5);
         $Record(
            $calls === 1 && $results === array_fill(0, 5, $category)
               && ($category !== 'Status' || $Remote->status === 500),
            "a {$category} failure is fetched once and replayed while cooling down",
            "calls={$calls}; results=" . json_encode($results) . "; status={$Remote->status}"
         );
      }

      // @ Hold: a set the origin confirmed is served for one cooldown past its
      //   TTL even while the origin is down; then the resolver fails closed,
      //   replays that failure, and recovers once the window ends
      try {
         $calls = 0;
         $Answer = $serve($first['body'], ['Cache-Control: max-age=1']);
         $Remote = new Remote('https://issuer.example/floor/hold', $origin($calls, $Answer), cooldown: 2);
         $Verifier = new JWT($Remote, 'RS256');
         $warm = $inspect($Verifier, $valid, 1);
         $started = hrtime(true);
         $Answer = $down();

         $pause($started, 1_300_000_000);
         $held = $inspect($Verifier, $valid, 2);
         $heldCalls = $calls;

         $pause($started, 2_150_000_000);
         $closed = $inspect($Verifier, $valid, 2);
         $closedCalls = $calls;

         $Answer = $serve($first['body'], ['Cache-Control: max-age=1']);
         $pause(hrtime(true), 2_150_000_000);
         $recovered = $inspect($Verifier, $valid, 2);

         $Record(
            $warm === ['valid'] && $held === ['valid', 'valid'] && $heldCalls === 1,
            'an origin-confirmed set is held for one cooldown past its TTL while the origin is down',
            'warm=' . json_encode($warm) . '; held=' . json_encode($held) . "; calls={$heldCalls}"
         );
         $Record(
            $closed === ['Network', 'Network'] && $closedCalls === 2,
            'after the hold the resolver fails closed and replays the failure without refetching',
            'closed=' . json_encode($closed) . "; calls={$closedCalls}"
         );
         $Record(
            $recovered === ['valid', 'valid'] && $calls === 3,
            'the origin is asked again once the failure window ends',
            'recovered=' . json_encode($recovered) . "; calls={$calls}"
         );
      }
      catch (Throwable $Exception) {
         $Record(false, 'hold / fail closed / recovery', 'threw ' . $Exception::class . ': ' . $Exception->getMessage());
      }

      // @ A failed forced refresh — an unknown `kid` any client can send — does
      //   not turn valid tokens into rejections while the set is held
      $calls = 0;
      $Answer = $serve($first['body'], ['Cache-Control: no-store']);
      $Remote = new Remote('https://issuer.example/floor/forced', $origin($calls, $Answer));
      $Verifier = new JWT($Remote, 'RS256');
      $before = $inspect($Verifier, $valid, 1);
      $Answer = $down();
      $miss = $inspect($Verifier, $unknown, 1);
      $after = $inspect($Verifier, $valid, 3);
      $Record(
         $before === ['valid'] && $miss === ['Network'] && $after === ['valid', 'valid', 'valid'] && $calls === 2,
         'a failed refresh on an unknown kid keeps the held set serving valid tokens',
         'before=' . json_encode($before) . '; miss=' . json_encode($miss) . '; after=' . json_encode($after) . "; calls={$calls}"
      );

      // @ Rotation: a key added at the IdP is accepted at once (refresh on the
      //   unknown kid); further unknown kids stay throttled
      $calls = 0;
      $Answer = $serve($first['body'], ['Cache-Control: no-store']);
      $Remote = new Remote('https://issuer.example/floor/rotation', $origin($calls, $Answer));
      $Verifier = new JWT($Remote, 'RS256');
      $old = $inspect($Verifier, $valid, 1);
      $Answer = $serve($both, ['Cache-Control: no-store']);
      $new = $inspect($Verifier, $rotated, 2);
      $junk = $inspect($Verifier, $unknown, 5);
      $Record(
         $old === ['valid'] && $new === ['valid', 'valid'] && $junk === array_fill(0, 5, 'Key') && $calls === 2,
         'a rotated-in key is accepted at once and unknown kids stay throttled',
         'old=' . json_encode($old) . '; new=' . json_encode($new) . '; junk=' . json_encode($junk) . "; calls={$calls}"
      );

      // @ `refresh()` is never floored; `fetch()` after it reuses the held set
      $calls = 0;
      $Answer = $serve($first['body'], ['Cache-Control: no-store']);
      $Remote = new Remote('https://issuer.example/floor/refresh', $origin($calls, $Answer));
      $Remote->fetch();
      $Remote->refresh();
      $Remote->refresh();
      $Held = $Remote->fetch();
      $Record(
         $calls === 3 && $Held instanceof KeySet,
         'refresh() always reaches the origin and fetch() then reuses the held set',
         "calls={$calls}; expected 3"
      );

      // @ Re-entrancy: a fetch while an attempt is in flight never asks again
      $calls = 0;
      $Inner = null;
      $Reentrant = null;
      $depth = 0;
      // ! Re-enter once only: an unguarded resolver would otherwise recurse
      $Answer = static function () use (&$Reentrant, &$Inner, &$depth, $first): Response {
         if ($depth++ === 0) {
            $Inner = $Reentrant?->fetch();
         }
         return new Response(200, $first['body'], ['Cache-Control: no-store']);
      };
      $Reentrant = new Remote('https://issuer.example/floor/reentrant', $origin($calls, $Answer));
      $Outer = $Reentrant->fetch();
      $Record(
         $calls === 1 && $Outer instanceof KeySet && $Inner === Failures::Network,
         'a re-entrant fetch during an origin attempt is refused without a second request',
         "calls={$calls}; inner=" . ($Inner instanceof Failures ? $Inner->name : get_debug_type($Inner))
      );

      // @ The window counts from the END of a slow attempt
      $calls = 0;
      $Answer = static function (): Response {
         usleep(1_200_000);
         return new Response(500, 'slow failure');
      };
      $Remote = new Remote('https://issuer.example/floor/slow', $origin($calls, $Answer), cooldown: 2);
      $started = hrtime(true);
      $slow = $Remote->fetch();
      $pause($started, 2_200_000_000);
      $again = $Remote->fetch();
      $Record(
         $slow === Failures::Status && $again === Failures::Status && $calls === 1,
         'a slow failed attempt starts its window when it ends',
         "calls={$calls}; expected 1"
      );

      // @ A slow SUCCESS holds its set from the end of the attempt
      $calls = 0;
      $Answer = static function () use ($first): Response {
         usleep(1_200_000);
         return new Response(200, $first['body'], ['Cache-Control: no-store']);
      };
      $Remote = new Remote('https://issuer.example/floor/slow-success', $origin($calls, $Answer), cooldown: 2);
      $started = hrtime(true);
      $Remote->fetch();
      $Answer = $down();
      $pause($started, 2_300_000_000);
      $kept = $Remote->fetch();
      $Record(
         $kept instanceof KeySet && $calls === 1,
         'a slow successful attempt holds its set from its end',
         "calls={$calls}; kept=" . get_debug_type($kept)
      );

      // @ An attempt longer than its window still refuses in-flight calls
      $calls = 0;
      $Inner = null;
      $Long = null;
      $depth = 0;
      $Answer = static function () use (&$Long, &$Inner, &$depth, $first): Response {
         if ($depth++ === 0) {
            usleep(1_300_000);
            $Inner = $Long?->fetch();
         }
         return new Response(200, $first['body'], ['Cache-Control: no-store']);
      };
      $Long = new Remote('https://issuer.example/floor/long-flight', $origin($calls, $Answer), cooldown: 1);
      $Long->fetch();
      $Record(
         $Inner === Failures::Network && $calls === 1,
         'an attempt longer than its window still refuses in-flight calls',
         'inner=' . ($Inner instanceof Failures ? $Inner->name : get_debug_type($Inner)) . "; calls={$calls}"
      );

      // @ The largest cooldown still lets the first fetch reach the origin
      $calls = 0;
      $Answer = $serve($first['body'], ['Cache-Control: no-store']);
      $Remote = new Remote('https://issuer.example/floor/cap', $origin($calls, $Answer), cooldown: 31_536_000);
      $capped = $inspect(new JWT($Remote, 'RS256'), $valid, 2);
      $Record(
         $calls === 1 && $capped === ['valid', 'valid'],
         'a one-year cooldown still fetches the first key set',
         "calls={$calls}; results=" . json_encode($capped)
      );

      // @ Shared Vault, two workers, no-store: one fetch per worker — nothing
      //   uncacheable enters the Vault
      $path = sys_get_temp_dir() . '/bootgly-jwt-remote-floor-' . bin2hex(random_bytes(8));
      try {
         $calls = 0;
         $Answer = $serve($first['body'], ['Cache-Control: no-store']);
         $URI = 'https://issuer.example/floor/fleet';
         $WorkerA = new Remote($URI, $origin($calls, $Answer));
         $WorkerA->cache(new Vault($path));
         $WorkerB = new Remote($URI, $origin($calls, $Answer));
         $WorkerB->cache(new Vault($path));
         $inspect(new JWT($WorkerA, 'RS256'), $forged, 5);
         $inspect(new JWT($WorkerB, 'RS256'), $forged, 5);
         $stored = new Vault($path)->read("jwt:jwks:body:v2:RS256:{$URI}");
         $Record(
            $calls === 2 && $stored === null,
            'two workers sharing a Vault cost one no-store fetch each and store nothing',
            "calls={$calls}; stored=" . json_encode($stored !== null)
         );
      }
      catch (Throwable $Exception) {
         $Record(false, 'shared Vault floor', 'threw ' . $Exception::class . ': ' . $Exception->getMessage());
      }
      finally {
         if (is_dir($path)) {
            $Iterator = new RecursiveIteratorIterator(
               new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
               RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($Iterator as $Info) {
               $Info->isDir() ? rmdir($Info->getPathname()) : unlink($Info->getPathname());
            }
            rmdir($path);
         }
      }

      // @ Algorithm pre-gate: an RS256 resolver never fetches for an HS256 header
      $calls = 0;
      $Answer = $serve($first['body'], ['Cache-Control: no-store']);
      $Remote = new Remote('https://issuer.example/floor/algorithm', $origin($calls, $Answer));
      $gated = $inspect(new JWT($Remote, 'RS256'), $symmetric, 3);
      $Record(
         $calls === 0 && $gated === ['Key', 'Key', 'Key'],
         'an HS256 header against an RS256 resolver is refused without a fetch',
         "calls={$calls}; results=" . json_encode($gated)
      );

      $calls = 0;
      $Open = new Remote('https://issuer.example/floor/any', $origin($calls, $Answer), algorithm: null);
      $open = $inspect(new JWT($Open, 'RS256'), $valid, 1);
      $Record(
         $calls === 1 && $open === ['valid'],
         'control: an unpinned resolver still verifies RS256',
         "calls={$calls}; results=" . json_encode($open)
      );

      // @ The operator's shorter TTL bounds the window: a key the IdP removed
      //   is refused once `$TTL` has passed, not a whole cooldown later
      $calls = 0;
      $Answer = $serve($first['body'], ['Cache-Control: max-age=3600']);
      $Remote = new Remote('https://issuer.example/floor/ttl', $origin($calls, $Answer), ttl: 1);
      $Verifier = new JWT($Remote, 'RS256');
      $warm = $inspect($Verifier, $valid, 1);
      $started = hrtime(true);
      $Answer = $serve($second['body'], ['Cache-Control: max-age=3600']);
      $pause($started, 1_200_000_000);
      $removed = $inspect($Verifier, $valid, 1);
      $Record(
         $warm === ['valid'] && $removed === ['Key'] && $calls === 2,
         'a positive TTL shorter than the cooldown bounds the window',
         'warm=' . json_encode($warm) . '; removed=' . json_encode($removed) . "; calls={$calls}"
      );

      // @ ... but a failure still pauses the origin for a whole cooldown
      $calls = 0;
      $Answer = $down();
      $Remote = new Remote('https://issuer.example/floor/ttl-failure', $origin($calls, $Answer), ttl: 1);
      $Verifier = new JWT($Remote, 'RS256');
      $failed = $inspect($Verifier, $valid, 1);
      $pause(hrtime(true), 1_200_000_000);
      $still = $inspect($Verifier, $valid, 1);
      $Record(
         $failed === ['Network'] && $still === ['Network'] && $calls === 1,
         'a failure pauses the origin for a whole cooldown even under a shorter TTL',
         'failed=' . json_encode($failed) . '; still=' . json_encode($still) . "; calls={$calls}"
      );

      // @ Deadlines are fixed when an attempt ends: raising `cooldown` later
      //   never brings back a set whose hold is over
      $calls = 0;
      $Answer = $serve($first['body'], ['Cache-Control: no-store']);
      $Remote = new Remote('https://issuer.example/floor/raise', $origin($calls, $Answer), cooldown: 1);
      $Verifier = new JWT($Remote, 'RS256');
      $inspect($Verifier, $valid, 1);
      $started = hrtime(true);
      $Answer = $down();
      $pause($started, 1_100_000_000);
      $closed = $inspect($Verifier, $valid, 1);
      $closedAt = hrtime(true);
      $Remote->cooldown = 600;
      $raised = $inspect($Verifier, $valid, 2);
      $Record(
         $closed === ['Network'] && $raised === ['Network', 'Network'] && $calls === 2,
         'raising the cooldown does not resurrect a set whose hold is over',
         'closed=' . json_encode($closed) . '; raised=' . json_encode($raised) . "; calls={$calls}"
      );
      // @ ... and the pause fixed by that failed attempt still ends on time
      $pause($closedAt, 1_100_000_000);
      $Answer = $serve($first['body'], ['Cache-Control: no-store']);
      $reopened = $inspect($Verifier, $valid, 1);
      $Record(
         $reopened === ['valid'] && $calls === 3,
         'a pause fixed when an attempt ended is not stretched by a later cooldown',
         'reopened=' . json_encode($reopened) . "; calls={$calls}"
      );

      // @ Rotation on a no-cache IdP behind a shared Vault: every worker keeps
      //   its own refresh, so each accepts the rotated-in key at once
      $path = sys_get_temp_dir() . '/bootgly-jwt-remote-floor-' . bin2hex(random_bytes(8));
      try {
         $calls = 0;
         $Answer = $serve($first['body'], ['Cache-Control: no-cache']);
         $URI = 'https://issuer.example/floor/fleet-rotation';
         $WorkerA = new Remote($URI, $origin($calls, $Answer));
         $WorkerA->cache(new Vault($path));
         $WorkerB = new Remote($URI, $origin($calls, $Answer));
         $WorkerB->cache(new Vault($path));
         $VerifierA = new JWT($WorkerA, 'RS256');
         $VerifierB = new JWT($WorkerB, 'RS256');
         $warm = [...$inspect($VerifierA, $valid, 1), ...$inspect($VerifierB, $valid, 1)];
         $Answer = $serve($both, ['Cache-Control: no-cache']);
         $rotation = [...$inspect($VerifierA, $rotated, 1), ...$inspect($VerifierB, $rotated, 1)];
         $Record(
            $warm === ['valid', 'valid'] && $rotation === ['valid', 'valid'] && $calls === 4,
            'every worker behind a shared Vault refreshes a no-cache set on a rotated-in key',
            'warm=' . json_encode($warm) . '; rotation=' . json_encode($rotation) . "; calls={$calls}"
         );
         // @ ... and further unknown kids stay throttled on each worker
         $junk = [...$inspect($VerifierA, $unknown, 5), ...$inspect($VerifierB, $unknown, 5)];
         $Record(
            $junk === array_fill(0, 10, 'Key') && $calls === 4,
            'unknown kids on a no-cache set behind a Vault stay throttled per worker',
            'junk=' . json_encode($junk) . "; calls={$calls}"
         );

         // @ One refresh per cooldown per worker, across both throttle paths: a
         //   fresh set takes the fleet claim, the same set held past its TTL the
         //   local one
         $calls = 0;
         $Answer = $serve($first['body'], ['Cache-Control: max-age=2']);
         $Dual = new Remote('https://issuer.example/floor/dual', $origin($calls, $Answer));
         $Dual->cache(new Vault($path));
         $VerifierD = new JWT($Dual, 'RS256');
         $inspect($VerifierD, $valid, 1);
         $fresh = $inspect($VerifierD, $unknown, 1);
         $pause(hrtime(true), 2_100_000_000);
         $held = $inspect($VerifierD, $unknown, 1);
         $Record(
            $fresh === ['Key'] && $held === ['Key'] && $calls === 2,
            'one unknown-kid refresh per cooldown per worker across both throttle paths',
            'fresh=' . json_encode($fresh) . '; held=' . json_encode($held) . "; calls={$calls}"
         );

         // @ A cacheable set another worker stored beats this worker's replayed
         //   failure: the shared record is read before the origin gate
         $callsA = 0;
         $callsB = 0;
         $AnswerA = static fn (): Response => new Response(500, 'unavailable');
         $AnswerB = $serve($first['body'], ['Cache-Control: max-age=3600']);
         $URI = 'https://issuer.example/floor/fleet-recovery';
         $WorkerA = new Remote($URI, $origin($callsA, $AnswerA));
         $WorkerA->cache(new Vault($path));
         $WorkerB = new Remote($URI, $origin($callsB, $AnswerB));
         $WorkerB->cache(new Vault($path));
         $failedA = $WorkerA->fetch();
         $storedB = $WorkerB->fetch();
         $readA = $inspect(new JWT($WorkerA, 'RS256'), $valid, 2);
         $Record(
            $failedA === Failures::Status && $storedB instanceof KeySet
               && $readA === ['valid', 'valid'] && $callsA === 1,
            'a set another worker stored in the Vault is read before the failure replay',
            'readA=' . json_encode($readA) . "; callsA={$callsA}"
         );

         // @ A set only read from the Vault is never held past its deadline
         $callsW = 0;
         $callsR = 0;
         $AnswerW = $serve($first['body'], ['Cache-Control: max-age=1']);
         $AnswerR = $serve($first['body'], ['Cache-Control: max-age=1']);
         $URI = 'https://issuer.example/floor/fleet-deadline';
         $Writer = new Remote($URI, $origin($callsW, $AnswerW));
         $Writer->cache(new Vault($path));
         $Reader = new Remote($URI, $origin($callsR, $AnswerR), cooldown: 31_536_000);
         $Reader->cache(new Vault($path));
         // ! Start early in a second: the writer's record must still be live
         //   when the reader reads it
         while (fmod(microtime(true), 1.0) >= 0.5) {
            usleep(10_000);
         }
         $Writer->fetch();
         $Reader->fetch();
         $readFirst = $callsR;
         $deadline = $Writer->expires;
         $waited = hrtime(true);
         while (time() < $deadline && hrtime(true) - $waited < 3_000_000_000) {
            usleep(20_000);
         }
         $Reader->fetch();
         $Record(
            $callsW === 1 && $readFirst === 0 && $callsR === 1,
            'a set only read from the Vault is not held past its deadline',
            "callsW={$callsW}; first read calls={$readFirst}; callsR={$callsR}; expected 1, 0 and 1"
         );
      }
      catch (Throwable $Exception) {
         $Record(false, 'shared Vault legs', 'threw ' . $Exception::class . ': ' . $Exception->getMessage());
      }
      finally {
         if (is_dir($path)) {
            $Iterator = new RecursiveIteratorIterator(
               new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
               RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($Iterator as $Info) {
               $Info->isDir() ? rmdir($Info->getPathname()) : unlink($Info->getPathname());
            }
            rmdir($path);
         }
      }

      // @ A cold resolver asks the origin ONCE for an unknown kid: the set it
      //   just fetched is not fetched again by the refresh
      foreach (['no-store' => ['Cache-Control: no-store'], 'cacheable' => []] as $label => $headers) {
         $calls = 0;
         $Answer = $serve($first['body'], $headers);
         $Remote = new Remote("https://issuer.example/floor/cold/{$label}", $origin($calls, $Answer));
         $cold = $inspect(new JWT($Remote, 'RS256'), $unknown, 1);
         $Record(
            $calls === 1 && $cold === ['Key'],
            "a cold unknown-kid token costs one origin fetch ({$label})",
            "calls={$calls}; results=" . json_encode($cold)
         );
      }

      // @ Failure bookkeeping: the pre-gate reports its own reason after an
      //   outage, and each attempt replays its own failure
      $calls = 0;
      $Answer = $down();
      $Remote = new Remote('https://issuer.example/floor/reasons', $origin($calls, $Answer), cooldown: 1);
      $Verifier = new JWT($Remote, 'RS256');
      $outage = $inspect($Verifier, $valid, 1);
      $gated = $inspect($Verifier, $symmetric, 1);
      $Record(
         $outage === ['Network'] && $gated === ['Key'],
         'after an outage the algorithm pre-gate still reports Key',
         'outage=' . json_encode($outage) . '; gated=' . json_encode($gated)
      );

      $calls = 0;
      $Answer = static fn (): Response => new Response(500, 'unavailable');
      $Remote = new Remote('https://issuer.example/floor/latest', $origin($calls, $Answer), cooldown: 1);
      $Verifier = new JWT($Remote, 'RS256');
      $first500 = $inspect($Verifier, $valid, 1);
      $started = hrtime(true);
      $Answer = $down();
      $pause($started, 1_100_000_000);
      $latest = $inspect($Verifier, $valid, 2);
      $Record(
         $first500 === ['Status'] && $latest === ['Network', 'Network'] && $calls === 2,
         'the replay reports the latest attempt failure',
         'first=' . json_encode($first500) . '; latest=' . json_encode($latest) . "; calls={$calls}"
      );

      // @ A call during an attempt in flight gets Network — never the category
      //   of an older, finished attempt
      $calls = 0;
      $Inner = null;
      $Flight = null;
      $depth = 0;
      $Answer = static fn (): Response => new Response(500, 'unavailable');
      $Flight = new Remote('https://issuer.example/floor/flight', $origin($calls, $Answer), cooldown: 1);
      $older = $Flight->fetch();
      $started = hrtime(true);
      $Answer = static function () use (&$Flight, &$Inner, &$depth, $first): Response {
         if ($depth++ === 0) {
            $Inner = $Flight?->fetch();
         }
         return new Response(200, $first['body'], ['Cache-Control: no-store']);
      };
      $pause($started, 1_100_000_000);
      $recovered = $Flight->fetch();
      $Record(
         $older === Failures::Status && $recovered instanceof KeySet
            && $Inner === Failures::Network && $calls === 2,
         'a call during an attempt in flight gets Network, not an older failure',
         'inner=' . ($Inner instanceof Failures ? $Inner->name : get_debug_type($Inner)) . "; calls={$calls}"
      );

      // @ `cooldown` is validated at construction and on assignment
      $refused = [];
      foreach ([-1, 31_536_001] as $value) {
         try {
            new Remote('https://issuer.example/floor/bounds', static fn (): string => '', cooldown: $value);
            $refused[] = "built:{$value}";
         }
         catch (InvalidArgumentException) {
            $refused[] = "refused:{$value}";
         }
      }
      $Bounded = new Remote('https://issuer.example/floor/bounds', static fn (): string => '', cooldown: 7);
      foreach ([-1, 31_536_001] as $value) {
         try {
            $Bounded->cooldown = $value;
            $refused[] = "assigned:{$value}";
         }
         catch (InvalidArgumentException) {
            $refused[] = "kept:{$Bounded->cooldown}";
         }
      }
      $Record(
         $refused === ['refused:-1', 'refused:31536001', 'kept:7', 'kept:7'],
         'cooldown outside [0, one year] is refused and the last valid value kept',
         'observed=' . json_encode($refused)
      );

      // @ Yield only after every probe ran and the Vault fixture is gone
      foreach ($outcomes as $outcome) {
         yield assert(
            assertion: $outcome['passed'],
            description: $outcome['description']
         );
      }
   }
);
