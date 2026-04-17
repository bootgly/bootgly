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
 * PoC — `Content-Length` accepts negative values (Audit finding #3).
 *
 * Attack:
 *   The fast path in `Request::decode()` parses `Content-Length` via:
 *
 *     if ( $_ = strpos($header_raw, "\r\nContent-Length: ") ) {
 *        $content_length = (int) substr($header_raw, $_ + 18, 10);
 *     }
 *
 *   `(int) "-10..."` = `-10` — a negative length is silently accepted.
 *   Then `$length += $content_length` yields a Request length SMALLER than
 *   the raw bytes in the TCP buffer. The Packages pipelining loop then
 *   reinterprets the extra bytes as a second pipelined request — CL/CL
 *   request smuggling behind any fronting proxy that normalises the sign
 *   differently. Even without a proxy, the negative value silently breaks
 *   invariants in downstream middleware (BodyParser, RateLimit, upload
 *   quotas — they all mis-account `Body->length`).
 *
 * Expected (fixed) behaviour: reject with `400 Bad Request`
 * (RFC 9112 §6.1 — `Content-Length = 1*DIGIT`).
 */

return new Specification(
   description: 'Content-Length with negative value must be rejected with 400',
   Separator: new Separator(line: true),

   requests: [
      function (string $hostPort): string {
         return "POST /smuggle HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Content-Length: -10\r\n"
            . "\r\n"
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

      // @ Keep previous test's routes alive — each completed test request pops the
      //   next test's response handler (Bootgly\API\Workables\Server::boot()), so
      //   test 2.01's priming #2 may consume this handler. Without the route it
      //   registers, priming #2 would 404 and 2.01 would fail unrelated.
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
      $response = $responses[0] ?? '';

      if ($response === '') {
         return 'Server returned no response — harness could not read the response.';
      }

      if (str_contains($response, 'SMUGGLED-REACHED')) {
         Vars::$labels = ['HTTP Response (truncated):'];
         dump(json_encode(substr($response, 0, 300)));
         return 'Smuggled GET /smuggled was dispatched as a pipelined request '
              . '— negative Content-Length caused Request::decode() to '
              . 'under-report the request length.';
      }

      if (str_contains($response, 'SHOULD-NOT-REACH-HANDLER')) {
         Vars::$labels = ['HTTP Response (truncated):'];
         dump(json_encode(substr($response, 0, 300)));
         return 'POST /smuggle handler ran despite a malformed Content-Length '
              . '(negative value). Server must reject with 400 Bad Request.';
      }

      if ( ! str_contains($response, '400 Bad Request')) {
         Vars::$labels = ['HTTP Response (truncated):'];
         dump(json_encode(substr($response, 0, 300)));
         return 'Server must reject Content-Length: -10 with 400 Bad Request '
              . '(RFC 9112 §6.1 — Content-Length = 1*DIGIT).';
      }

      return true;
   }
);
