<?php

use function json_encode;
use function str_contains;
use function str_repeat;
use function substr;

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * PoC — Multipart boundary parser accepts non-conforming values
 *        (Audit finding #4 — [HIGH] — boundary injection + length-cap).
 *
 * `Request::decode()` parses the boundary with:
 *   ```
 *   preg_match('/boundary="?(\S+)"?/', substr($header_raw, $ctPos, 200), $bMatch)
 *   $multipartBoundary = trim('--' . $bMatch[1], '"');
 *   ```
 *   `\S+` is greedy until whitespace and there is no charset / length cap.
 *   RFC 7578 §4.1 / RFC 2046 §5.1.1 restrict the boundary to
 *   `bchars = DIGIT / ALPHA / "'" / "(" / ")" / "+" / "_" / "," / "-" /
 *            "." / "/" / ":" / "=" / "?" / SP` and max 70 characters.
 *
 *   Exploits:
 *     A — inject a quote-with-parameters (`X";foo="bar`) → the raw bytes
 *         become the `strpos` target on every body chunk; an attacker can
 *         deliberately desync the boundary between the origin and an
 *         upstream proxy that parses the quoted form correctly.
 *     B — 4 KiB boundary → `strpos($data, $hugeBoundary)` Boyer-Moore scan
 *         runs on every streamed body chunk (algorithmic DoS).
 *
 * Expected (fixed) behaviour: reject both with `400 Bad Request` at
 *   `Request::decode()` time, before any multipart state is initialised.
 */

return new Specification(
   description: 'Multipart boundary must conform to RFC 7578 (charset + length ≤ 70)',
   Separator: new Separator(line: true),

   requests: [
      // Attack A — quote-injection inside the boundary value
      function (string $hostPort): string {
         $body = "hello";
         return "POST /upload HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Content-Type: multipart/form-data; boundary=X\";foo=\"bar\r\n"
            . "Content-Length: " . strlen($body) . "\r\n"
            . "\r\n"
            . $body;
      },
      // Attack B — 4 KiB boundary (algorithmic DoS surface)
      function (string $hostPort): string {
         $huge = str_repeat('A', 4096);
         $body = "hello";
         return "POST /upload HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Content-Type: multipart/form-data; boundary={$huge}\r\n"
            . "Content-Length: " . strlen($body) . "\r\n"
            . "\r\n"
            . $body;
      },
   ],

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/upload', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'UPLOAD-HANDLED');
      }, POST);

      // @ Keep earlier suite routes alive (handler-queue pops).
      yield $Router->route('/chunked', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'CHUNK-HANDLED');
      }, POST);

      yield $Router->route('/session-takeover', function (Request $Request, Response $Response) {
         $role = $Request->Session->get('role', 'none');
         return $Response(code: 200, body: 'SESSION_ROLE:' . (string) $role);
      }, GET);

      yield $Router->route('/smuggle', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'SHOULD-NOT-REACH-HANDLER');
      }, POST);
      yield $Router->route('/smuggled', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'SMUGGLED-REACHED');
      }, GET);

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
         $label = $i === 0 ? 'Attack A (quote injection)' : 'Attack B (4KiB boundary)';

         if ($response === '') {
            return "{$label}: server returned no response.";
         }

         if (str_contains($response, 'UPLOAD-HANDLED')) {
            Vars::$labels = ["{$label} — HTTP Response (truncated):"];
            dump(json_encode(substr($response, 0, 300)));
            return "{$label}: handler ran with a malformed multipart "
                 . 'boundary. Server must reject with 400 Bad Request '
                 . '(RFC 7578 §4.1 — bchars, 1-70 chars).';
         }

         if ( ! str_contains($response, '400 Bad Request')) {
            Vars::$labels = ["{$label} — HTTP Response (truncated):"];
            dump(json_encode(substr($response, 0, 300)));
            return "{$label}: server must reject with 400 Bad Request.";
         }
      }

      return true;
   }
);
