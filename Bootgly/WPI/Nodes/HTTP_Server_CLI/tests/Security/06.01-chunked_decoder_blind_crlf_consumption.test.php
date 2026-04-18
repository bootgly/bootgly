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
 * PoC — Chunked decoder accepts non-CRLF chunk-data terminator and
 *        non-hex chunk-size (Audit finding #3, CRITICAL).
 *
 * `Decoder_Chunked` skips 2 bytes after each chunk-data block without
 * checking they are `\r\n`:
 *   ```
 *   if (strlen(self::$buffer) < 2) return 0;
 *   self::$buffer = substr(self::$buffer, 2);   // bug: not asserted CRLF
 *   ```
 *   It also parses size via `(int) hexdec(trim($sizeLine))` — `hexdec`
 *   silently truncates on non-hex chars (`0x10` → `0`, `-1` → `0`,
 *   `5 garbage` → `5`). RFC 9112 §7.1 requires
 *   `chunk = 1*HEXDIG [chunk-ext] CRLF chunk-data CRLF`.
 *
 *   Any proxy/origin disagreement on those acceptance rules is the root
 *   cause of TE-smuggling chains. Even without cross-request smuggling,
 *   silently accepting malformed framing corrupts body bytes delivered to
 *   user handlers — the attacker decides where their body starts/ends.
 *
 * Wire payload of Attack A (non-CRLF terminator):
 *     `5\r\nHELLOXX0\r\n\r\n`
 *   — the `XX` after `HELLO` MUST terminate the stream with 400.
 *
 * Wire payload of Attack B (non-hex size with space):
 *     `5 garbage\r\nHELLO\r\n0\r\n\r\n`
 *   — `hexdec` stops at space → size=5, silently accepted.
 *
 * Expected (fixed) behaviour: 400 Bad Request on both payloads.
 */

return new Specification(
   description: 'Chunked decoder must validate chunk-data CRLF and chunk-size hex-digit syntax',
   Separator: new Separator(line: true),

   requests: [
      // Attack A — non-CRLF chunk-data terminator (`XX` instead of `\r\n`)
      function (string $hostPort): string {
         $body = "5\r\nHELLOXX0\r\n\r\n";
         return "POST /chunked HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Transfer-Encoding: chunked\r\n"
            . "\r\n"
            . $body;
      },
      // Attack B — non-hex chunk-size (`5 garbage` accepted as 5)
      function (string $hostPort): string {
         $body = "5 garbage\r\nHELLO\r\n0\r\n\r\n";
         return "POST /chunked HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Transfer-Encoding: chunked\r\n"
            . "\r\n"
            . $body;
      },
   ],

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/chunked', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'CHUNK-HANDLED');
      }, POST);

      // @ Keep earlier suite routes alive (handler-queue pops).
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
         $label = $i === 0 ? 'Attack A (non-CRLF terminator)' : 'Attack B (non-hex size)';

         if ($response === '') {
            return "{$label}: server returned no response.";
         }

         if (str_contains($response, 'CHUNK-HANDLED')) {
            Vars::$labels = ["{$label} — HTTP Response (truncated):"];
            dump(json_encode(substr($response, 0, 300)));
            return "{$label}: handler ran despite malformed chunked framing. "
                 . 'Server must reject with 400 Bad Request '
                 . '(RFC 9112 §7.1 — chunk-size = 1*HEXDIG, terminator = CRLF).';
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
