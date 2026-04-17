<?php

use function json_encode;
use function str_contains;
use function substr;

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * PoC — `Content-Length` strict-parse bypass (Audit finding #2, sub-items 1 & 3).
 *
 * Attack A — lowercase header + double space:
 *   The fast path is case-sensitive (`"\r\nContent-Length: "`) and the regex
 *   fallback `/\r\ncontent-length: ?(\d+)/i` accepts zero-or-one space only.
 *   A header spelled `Content-length:  10\r\n` (lowercase `l`, two spaces)
 *   misses BOTH paths → `$content_length` stays unset → the decoder treats
 *   the request as having NO body. The 10 attacker-chosen body bytes are
 *   left in the TCP buffer and the Packages pipelining loop re-interprets
 *   them as the next pipelined request — classic CL desynchronisation.
 *
 *   RFC 9110 §5.6.3 allows OWS (SP/HTAB) between `:` and value, so a proper
 *   parse of `Content-length:  10` yields `CL=10`. Either outcome is
 *   acceptable as long as the 10 body bytes are consumed (or the request
 *   is rejected) — the bug is only the SMUGGLING, i.e. dispatching
 *   `GET /smuggled` as a separate pipelined request.
 *
 * Attack B — comma-list value `Content-Length: 10, 20`:
 *   Fast path extracts slice `"10, 20\r\n..."`, first byte is `'1'` so the
 *   existing `ctype_digit($slice[0])` check passes, and `(int)` truncates
 *   at the comma yielding `10`. A proxy that honours the LAST value (or
 *   rejects) disagrees with the server → desync. RFC 9112 §6.1 requires
 *   `1*DIGIT` (single value); list form must be rejected with 400.
 *
 * Expected (fixed) behaviour: `GET /smuggled` must NEVER be dispatched.
 */

return new Specification(
   description: 'Malformed Content-Length variants must not smuggle a pipelined request',
   Separator: new Separator(line: true),

   requests: [
      // Attack A: lowercase `Content-length:` with two spaces before value
      function (string $hostPort): string {
         return "POST /smuggle HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Content-length:  10\r\n"
            . "\r\n"
            . "GET /smuggled HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "\r\n";
      },
      // Attack B: comma-separated CL list — (int) truncates at comma
      function (string $hostPort): string {
         return "POST /smuggle HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Content-Length: 10, 20\r\n"
            . "\r\n"
            . "0123456789"
            . "GET /smuggled HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "\r\n";
      },
   ],

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/smuggle', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'SHOULD-NOT-REACH-HANDLER');
      }, POST);

      yield $Router->route('/smuggled', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'SMUGGLED-REACHED');
      }, GET);

      // @ Keep earlier suite routes alive (handler-queue pops — see 3.01).
      yield $Router->route('/cache-bleed', function (Request $Request, Response $Response) {
         if (isset($Request->leaked)) {
            return $Response(code: 200, body: 'LEAKED:' . (string) $Request->leaked);
         }
         $Request->leaked = 'attacker-tenant';
         return $Response(code: 200, body: 'CLEAN');
      }, GET);

      yield $Router->route('/*', function (Request $Request, Response $Response) {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: function (array $responses): bool|string {
      foreach ($responses as $i => $response) {
         $label = $i === 0 ? 'Attack A (lowercase+2 spaces)' : 'Attack B (comma list)';

         if ($response === '') {
            return "{$label}: server returned no response.";
         }

         // The only bug is smuggling: GET /smuggled dispatched as its own
         //   pipelined request. Accepting CL=10 (RFC-legal OWS) and running
         //   /smuggle is fine; rejecting with 400 is also fine.
         if (str_contains($response, 'SMUGGLED-REACHED')) {
            Vars::$labels = ["{$label} — HTTP Response (truncated):"];
            dump(json_encode(substr($response, 0, 300)));
            return "{$label}: smuggled GET /smuggled was dispatched as a "
                 . 'pipelined request — malformed Content-Length caused '
                 . 'Request::decode() to mis-frame the request body.';
         }
      }

      return true;
   }
);
