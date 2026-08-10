<?php

use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * Security PoC M5 (2026-07-27) — the route cache must honour HTTP cache
 * directives instead of treating a route-level TTL as absolute.
 *
 * Replay inspected only `Cookie`/`Authorization`, and storage inspected
 * method/status/body/credentials/`Vary` — neither parsed `Cache-Control`. So a
 * handler that discovers its response is private and emits `no-store` still had
 * its raw wire stored and replayed, and a client explicitly sending `no-cache`
 * could not force the handler/validation path.
 *
 * Three routes, each with `cache: ['TTL' => 60]`:
 *   /m5-private   — emits `Cache-Control: private` on every call
 *   /m5-nostore   — emits `Cache-Control: no-store` on every call
 *   /m5-plain     — emits nothing (the control: caching must still work)
 *
 * Every handler stamps its own invocation counter, so a replay is visible as a
 * repeated counter and a miss as an incremented one.
 */
$private = 0;
$nostore = 0;
$plain = 0;
$nocache = 0;
$qualified = 0;

return new Test(
   description: 'route cache must honour no-store/private on storage and no-cache on replay',
   Separator: new Separator(line: true),

   requests: [
      // 1/2 — a `private` response must never be replayed.
      static fn (): string => "GET /m5-private HTTP/1.1\r\nHost: localhost\r\n\r\n",
      static fn (): string => "GET /m5-private HTTP/1.1\r\nHost: localhost\r\n\r\n",
      // 3/4 — a `no-store` response must never be replayed.
      static fn (): string => "GET /m5-nostore HTTP/1.1\r\nHost: localhost\r\n\r\n",
      static fn (): string => "GET /m5-nostore HTTP/1.1\r\nHost: localhost\r\n\r\n",
      // 5/6 — control: an ordinary cached route MUST replay.
      static fn (): string => "GET /m5-plain HTTP/1.1\r\nHost: localhost\r\n\r\n",
      static fn (): string => "GET /m5-plain HTTP/1.1\r\nHost: localhost\r\n\r\n",
      // 7/8 — response `no-cache`: a raw-wire cache cannot revalidate.
      static fn (): string => "GET /m5-nocache HTTP/1.1\r\nHost: localhost\r\n\r\n",
      static fn (): string => "GET /m5-nocache HTTP/1.1\r\nHost: localhost\r\n\r\n",
      // 9/10 — qualified `private="Set-Cookie"`: cannot drop a named field.
      static fn (): string => "GET /m5-qualified HTTP/1.1\r\nHost: localhost\r\n\r\n",
      static fn (): string => "GET /m5-qualified HTTP/1.1\r\nHost: localhost\r\n\r\n",
      // 11 — a client asking for no-cache must reach the handler again.
      static fn (): string => "GET /m5-plain HTTP/1.1\r\nHost: localhost\r\n"
         . "Cache-Control: no-cache\r\n\r\n",
   ],

   response: static function (Request $Request, Response $Response, Router $Router) use (
      &$private,
      &$nostore,
      &$plain,
      &$nocache,
      &$qualified
   ) {
      yield $Router->route('/m5-private', function (
         Request $Request,
         Response $Response
      ) use (&$private): Response {
         $private++;
         $Response->Header->set('Cache-Control', 'private');

         return $Response(body: "M5-PRIVATE:{$private}");
      }, GET, cache: ['TTL' => 60]);

      yield $Router->route('/m5-nostore', function (
         Request $Request,
         Response $Response
      ) use (&$nostore): Response {
         $nostore++;
         $Response->Header->set('Cache-Control', 'no-store');

         return $Response(body: "M5-NOSTORE:{$nostore}");
      }, GET, cache: ['TTL' => 60]);

      yield $Router->route('/m5-nocache', function (
         Request $Request,
         Response $Response
      ) use (&$nocache): Response {
         $nocache++;
         $Response->Header->set('Cache-Control', 'no-cache');

         return $Response(body: "M5-NOCACHE:{$nocache}");
      }, GET, cache: ['TTL' => 60]);

      yield $Router->route('/m5-qualified', function (
         Request $Request,
         Response $Response
      ) use (&$qualified): Response {
         $qualified++;
         $Response->Header->set('Cache-Control', 'private="Set-Cookie"');

         return $Response(body: "M5-QUALIFIED:{$qualified}");
      }, GET, cache: ['TTL' => 60]);

      yield $Router->route('/m5-plain', function (
         Request $Request,
         Response $Response
      ) use (&$plain): Response {
         $plain++;

         return $Response(body: "M5-PLAIN:{$plain}");
      }, GET, cache: ['TTL' => 60]);
   },

   test: static function (array $responses): bool|string {
      if (count($responses) !== 11) {
         return 'M5 fixture failed: expected eleven live responses, got ' . count($responses) . '.';
      }

      [$p1, $p2, $n1, $n2, $c1, $c2, $nc1, $nc2, $q1, $q2, $revalidate] = $responses;

      // ? Control — an ordinary cached route MUST still replay, otherwise this
      //   case would pass simply because caching stopped working.
      if (! str_contains($c1, 'M5-PLAIN:1') || ! str_contains($c2, 'M5-PLAIN:1')) {
         return 'M5 control failed: an ordinary cached route did not replay ('
            . json_encode([substr($c1, -40), substr($c2, -40)])
            . '), so the directive legs prove nothing.';
      }

      $violations = [];

      if (! str_contains($p1, 'M5-PRIVATE:1') || ! str_contains($p2, 'M5-PRIVATE:2')) {
         $violations[] = 'a `Cache-Control: private` response was stored and replayed ('
            . json_encode(substr($p2, -40)) . ', expected a second handler run)';
      }
      if (! str_contains($n1, 'M5-NOSTORE:1') || ! str_contains($n2, 'M5-NOSTORE:2')) {
         $violations[] = 'a `Cache-Control: no-store` response was stored and replayed ('
            . json_encode(substr($n2, -40)) . ', expected a second handler run)';
      }
      if (! str_contains($nc1, 'M5-NOCACHE:1') || ! str_contains($nc2, 'M5-NOCACHE:2')) {
         $violations[] = 'a `Cache-Control: no-cache` response was stored and replayed ('
            . json_encode(substr($nc2, -40)) . ') — a raw-wire cache cannot revalidate';
      }
      if (! str_contains($q1, 'M5-QUALIFIED:1') || ! str_contains($q2, 'M5-QUALIFIED:2')) {
         $violations[] = 'a qualified `private="Set-Cookie"` response was stored and replayed ('
            . json_encode(substr($q2, -40)) . ') — a raw-wire cache cannot drop a named field';
      }
      if (! str_contains($revalidate, 'M5-PLAIN:2')) {
         $violations[] = 'a request carrying `Cache-Control: no-cache` was answered from cache ('
            . json_encode(substr($revalidate, -40)) . ', expected the handler to run again)';
      }

      if ($violations !== []) {
         return 'CONFIRMED M5: the route cache ignored HTTP cache directives — '
            . implode('; ', $violations)
            . '. A route-level TTL overrode the application\'s own stated policy.';
      }

      return true;
   },
);
