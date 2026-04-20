<?php

use function json_decode;
use function json_encode;
use function str_contains;
use function str_repeat;
use function str_starts_with;
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
 *     C — filename `.htaccess` in multipart part must not preserve a leading
 *         dot after sanitization in `$_FILES['name']`.
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
      // Attack C — valid multipart with hidden-dot filename
      function (string $hostPort): string {
         $boundary = '---------------------------735323031399963166993862150';
         $body = ''
            . "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"file\"; filename=\".htaccess\"\r\n"
            . "Content-Type: text/plain\r\n"
            . "\r\n"
            . "deny from all\r\n"
            . "--{$boundary}--\r\n";

         return "POST /upload-hidden HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Content-Type: multipart/form-data; boundary={$boundary}\r\n"
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
         static $leaked = false;

         if ($leaked) {
            return $Response(code: 200, body: 'LEAKED:attacker-tenant');
         }
         $leaked = true;
         return $Response(code: 200, body: 'CLEAN');
      }, GET);

      yield $Router->route('/upload-hidden', function (Request $Request, Response $Response) {
         $file = $Request->files['file'] ?? null;
         $name = is_array($file) ? (string) ($file['name'] ?? '') : '';

         return $Response->Json->send([
            'name' => $name,
            'error' => is_array($file) ? (int) ($file['error'] ?? -1) : -1,
         ]);
      }, POST);

      yield $Router->route('/*', function (Request $Request, Response $Response) {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: function (array $responses): bool|string {
      foreach ($responses as $i => $response) {
         if ($i === 0 || $i === 1) {
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

            continue;
         }

         // Attack C assertions: upload should succeed but filename must not
         // preserve a leading dot.
         if ($i === 2) {
            if ($response === '') {
               return 'Attack C (leading-dot filename): server returned no response.';
            }

            if ( ! str_contains($response, '200 OK')) {
               Vars::$labels = ['Attack C — HTTP Response (truncated):'];
               dump(json_encode(substr($response, 0, 300)));
               return 'Attack C (leading-dot filename): expected 200 OK response.';
            }

            $parts = explode("\r\n\r\n", $response, 2);
            $json = $parts[1] ?? '';
            $decoded = json_decode($json, true);
            if (! is_array($decoded)) {
               return 'Attack C (leading-dot filename): server did not return JSON payload.';
            }

            $name = (string) ($decoded['name'] ?? '');
            if ($name === '') {
               return 'Attack C (leading-dot filename): upload did not produce a filename.';
            }

            if (str_starts_with($name, '.')) {
               return 'Attack C (leading-dot filename): vulnerability reproduced. '
                  . 'Sanitized filename still starts with a dot.';
            }

            continue;
         }

         return 'Unexpected extra response in 07.01 test payloads.';
      }

      if (count($responses) !== 3) {
         return '07.01 expected exactly 3 responses (A, B, C).';
      }

      return true;
   }
);
