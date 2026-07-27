<?php

use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


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

return new Specification(
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
      // 7 — a client asking for no-cache must reach the handler again.
      static fn (): string => "GET /m5-plain HTTP/1.1\r\nHost: localhost\r\n"
         . "Cache-Control: no-cache\r\n\r\n",
   ],

   response: static function (Request $Request, Response $Response, Router $Router) use (
      &$private,
      &$nostore,
      &$plain
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

      yield $Router->route('/m5-plain', function (
         Request $Request,
         Response $Response
      ) use (&$plain): Response {
         $plain++;

         return $Response(body: "M5-PLAIN:{$plain}");
      }, GET, cache: ['TTL' => 60]);
   },

   test: static function (array $responses): bool|string {
      if (count($responses) !== 7) {
         return 'M5 fixture failed: expected seven live responses, got ' . count($responses) . '.';
      }

      [$p1, $p2, $n1, $n2, $c1, $c2, $revalidate] = $responses;

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
