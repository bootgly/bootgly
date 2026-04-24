<?php

use function str_contains;
use function str_repeat;
use function strlen;
use function substr;

use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * PoC — BodyParser middleware mutates the *global* static
 * `Request::$maxBodySize` when it sees a lower per-route limit (the
 * so-called "push to decoder-level gate"). The mutation is permanent
 * and worker-wide, so a BodyParser(maxSize: 100) registered on a
 * single restrictive endpoint (e.g. `/webhook`) persistently caps
 * ALL other routes served by the same worker to 100 bytes.
 *
 * Attack scenario:
 *   Application mounts BodyParser(maxSize: 100) on `/webhook`.
 *   Attacker POSTs 200 bytes to `/webhook` — rejected (expected).
 *   Legitimate client then POSTs a 5 KB JSON body to `/api/data`
 *   (no BodyParser, default 10 MB cap applies) — rejected at
 *   decode time with 413 because the global was lowered to 100.
 *   Result: an attacker DoSes *unrelated* endpoints by tripping
 *   BodyParser once on a small-limit route.
 *
 * PoC design (self-contained):
 *   By the time this test runs, the earlier Security test
 *   `12.01-bodyparser_limit_bypass_decode_time` has already set
 *   `Request::$maxBodySize = 100` in this worker (it is also the
 *   intended behaviour of BodyParser on the current vulnerable
 *   build). This test then sends a 500-byte body to a route that
 *   declares NO BodyParser middleware and whose handler would
 *   happily accept it. On a vulnerable build the decoder returns
 *   413 before the handler is reached; on a fixed build the
 *   handler answers 200 OK with a known marker string.
 *
 * Expected outcomes:
 *   - Vulnerable build → decode-time 413, no "HANDLER-REACHED".
 *   - Fixed build      → 200 OK with "HANDLER-REACHED" body.
 */

return new Specification(
   description: 'BodyParser must not leak maxSize into global Request::$maxBodySize across routes',
   Separator: new Separator(line: true),

   // ! No middlewares at test level — target route is "unprotected".
   //   Any cap observed comes from prior global-state pollution.
   middlewares: [],

   request: function (string $hostPort): string {
      $body = str_repeat('Y', 500);
      $length = strlen($body);

      return "POST /leak-probe HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Content-Type: text/plain\r\n"
         . "Content-Length: {$length}\r\n"
         . "Connection: close\r\n"
         . "\r\n"
         . $body;
   },

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/leak-probe', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'HANDLER-REACHED');
      });
   },

   test: function (string $response): bool|string {
      if ($response === '') {
         return 'Empty response.';
      }

      if (str_contains($response, 'HANDLER-REACHED')) {
         // @ Fixed build — handler was reached with a 500-byte body
         //   despite a prior BodyParser(maxSize: 100) invocation on a
         //   different route. Global state is isolated.
         return true;
      }

      if (str_contains($response, '413')) {
         return 'LEAK OBSERVED: a prior BodyParser(maxSize: 100) on an '
            . 'unrelated route permanently lowered Request::$maxBodySize, '
            . 'so this 500-byte request was rejected at decode time on a '
            . 'route that has NO BodyParser middleware. '
            . 'Response: ' . substr($response, 0, 200);
      }

      return 'Unexpected response: ' . substr($response, 0, 200);
   }
);
