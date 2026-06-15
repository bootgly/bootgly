<?php

use function str_contains;

use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * PoC — Audit F-1: `Frame::parse()` never validates the request-line
 * `protocol` token; it accepts ANY three non-empty space-separated tokens
 * (`@[$method, $URI, $protocol] = explode(' ', $meta_raw, 3)`), and the
 * protocol is only ever consumed via EXACT equality:
 *
 *   - Frame.php   : `if ($protocol === 'HTTP/1.1' && $hostValue === '')` → 400
 *   - Request.php : `if ($allowedHosts !== [] && $Frame->hostValue !== '')` → allowlist
 *
 * Because the protocol is unconstrained, a client that sends a bogus
 * version (`HTTP/9.9`, `HTTP/1.1x`, ` HTTP/1.1`) makes the string NOT
 * exactly `HTTP/1.1`. Two controls then silently switch OFF together:
 *
 *   1. the mandatory-Host guard (RFC 9112 §3.2) — gated on `=== 'HTTP/1.1'`;
 *   2. the `$allowedHosts` enforcement — gated on `hostValue !== ''`.
 *
 * So a Host-less request on a bogus version escapes BOTH the
 * missing-Host rejection AND the Host allowlist, and is still dispatched
 * to the route handler against an unvalidated protocol.
 *
 * Claimed fix (Resolution 2026-06-11): reject any protocol other than
 * exactly `HTTP/1.1` / `HTTP/1.0` with `505 HTTP Version Not Supported`
 * BEFORE any framing decision. If that fix is present, the request below
 * is rejected 505 and the handler never runs.
 *
 * This PoC configures the allowlist to `['localhost']` and sends a
 * Host-less request on `HTTP/9.9`:
 *   - Vulnerable path: missing-Host guard + allowlist both skipped →
 *     handler dispatches → leak marker `F1-DISPATCHED`.
 *   - Fixed path: server rejects `505 HTTP Version Not Supported` before
 *     dispatch.
 *
 * Control reference: `GET /x HTTP/1.1\r\n\r\n` (exact version, no Host)
 * IS rejected `400 Bad Request` today — proving the bypass is the
 * protocol non-exactness, not a missing guard.
 */

// ! Configure allowlist ONCE for the suite, pre-fork (see 11.01 for the
//   same pattern). Only `localhost` is permitted; the bogus-version
//   request carries NO Host at all, so a correct server must still refuse
//   it (missing Host on a request that is not a valid older version).
Request::$allowedHosts = ['localhost'];


return new Specification(
   description: 'Frame::parse() must reject a non HTTP/1.0|1.1 protocol token (505) before framing',
   Separator: new Separator(line: true),

   request: function (): string {
      // @ Bogus version + NO Host header. Request-line matches the harness
      //   injection regex `#^[A-Z]+ \S+ HTTP/\d\.\d$#` (HTTP/9.9), so the
      //   `X-Bootgly-Test` index header IS injected and the handler is
      //   reachable IF the server dispatches.
      return "GET /f1-protocol HTTP/9.9\r\n"
         . "Connection: close\r\n"
         . "\r\n";
   },

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/f1-protocol', function (Request $Request, Response $Response) {
         // @ If this runs, a Host-less request on a bogus protocol was
         //   dispatched: both the mandatory-Host guard and the allowlist
         //   were bypassed via the unvalidated version token.
         return $Response(
            code: 200,
            body: 'F1-DISPATCHED:proto=' . $Request->protocol . ':host=' . $Request->host
         );
      });

      yield $Router->route('/*', function (Request $Request, Response $Response) {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: function ($response): bool|string {
      // @ Reset allowlist so other suites / the benchmark are not affected.
      Request::$allowedHosts = [];

      if (! \is_string($response) || $response === '') {
         return 'No response from server.';
      }

      if (str_contains($response, 'F1-DISPATCHED')) {
         return 'Frame::parse() accepted the unvalidated protocol token '
            . '`HTTP/9.9`: a Host-less request bypassed BOTH the mandatory-Host '
            . 'guard (gated on `protocol === "HTTP/1.1"`) and the `$allowedHosts` '
            . 'allowlist (gated on `hostValue !== ""`), and was dispatched to the '
            . 'route handler. Fix: reject any protocol other than exactly '
            . 'HTTP/1.1 / HTTP/1.0 with 505 HTTP Version Not Supported in '
            . 'Frame::parse() before any framing decision.';
      }

      // Fixed: bogus version refused before framing/dispatch.
      if (str_contains($response, '505')) {
         return true;
      }

      // A 400 (e.g. a future bare-LF/strict guard) also blocks dispatch,
      // but F-1's prescribed status for an unsupported version is 505.
      if (str_contains($response, '400 Bad Request')) {
         return true;
      }

      return 'Unexpected response (expected 505 HTTP Version Not Supported): '
         . substr($response, 0, 200);
   }
);
