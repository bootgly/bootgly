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

$Auth = new class implements Middleware {
   public int $runs = 0;

   public function process (object $Request, object $Response, Closure $Next): object
   {
      /** @var Request $Request */
      /** @var Response $Response */
      if (str_starts_with($Request->URI, '/h1/profile') === false) {
         return $Next($Request, $Response);
      }

      $this->runs++;

      // ! Two DIFFERENT valid principals — this is what case 54.02 never
      //   exercised: it only ever sent one valid credential plus denials.
      $identity = match ($Request->Header->get('X-API-Key')) {
         'alice-key' => 'alice',
         'bob-key' => 'bob',
         default => null,
      };

      if ($identity === null) {
         return $Response(code: 401, body: 'H1-DENIED');
      }

      $Request->identity = $identity;

      return $Next($Request, $Response);
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
   ],

   middlewares: [$Auth],

   response: static function (Request $Request, Response $Response, Router $Router) use (
      &$handlerRuns
   ) {
      yield $Router->route('/h1/profile', function (
         Request $Request,
         Response $Response
      ) use (&$handlerRuns): Response {
         $handlerRuns++;

         // ! Principal-specific output — exactly the shape the finding is about.
         return $Response(
            body: 'H1-PROFILE:' . ((string) $Request->identity) . ";handler={$handlerRuns}"
         );
      }, GET, cache: ['TTL' => 60]);
   },

   test: static function (array $responses): bool|string {
      if (count($responses) !== 5) {
         return 'H1 fixture failed: expected five live responses, got ' . count($responses) . '.';
      }

      [$alice, $bob, $aliceAgain, $invalid, $missing] = $responses;

      // ? Harness control — the primer must have reached the handler as Alice.
      if (! str_contains($alice, 'H1-PROFILE:alice')) {
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
      if (str_contains($bob, 'H1-PROFILE:alice')) {
         return 'CONFIRMED H1: a second VALID principal received the first principal\'s '
            . 'cached response. Bob authenticated as Bob and was served Alice\'s raw wire '
            . 'without his handler running — the route-cache key omits the admitted '
            . 'identity, and a route authenticated only by GLOBAL middleware still gets a '
            . 'TTL stamp. Bob received: ' . json_encode(substr($bob, 0, 220));
      }
      if (! str_contains($bob, 'H1-PROFILE:bob')) {
         return 'H1: Bob received neither his own profile nor Alice\'s — the route no longer '
            . 'serves an admitted principal at all: ' . json_encode(substr($bob, 0, 220));
      }

      // ? Alice must still get Alice after Bob's request.
      if (! str_contains($aliceAgain, 'H1-PROFILE:alice')) {
         return 'H1 control failed: Alice stopped receiving her own profile after Bob\'s '
            . 'request: ' . json_encode(substr($aliceAgain, 0, 220));
      }

      return true;
   },
);
