<?php

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * Security PoC N1/N2 — HTTP/1 head grammar and the 16 KiB head cap.
 *
 * N2: the documented 16 KiB request-head limit was only enforced while the
 * `\r\n\r\n` separator had NOT been found yet, so a complete oversized head
 * that arrived in one transport read was accepted — the limit depended on
 * packetization rather than on the head.
 *
 * N1: a nonempty head line without a colon was skipped silently, and a
 * field-name was only rejected for empty/SP/HTAB rather than required to be
 * RFC 9110 tchar. `Content-Length\x0B`, `Transfer-Encoding\x00` and
 * `Bad(Name)` were all accepted and then ignored by framing — precisely the
 * shape a tolerant intermediary can normalize back into a framing header.
 *
 * Controls: an ordinary request, an RFC-valid no-space `Header:value`, and a
 * head sized exactly AT the limit must all still succeed, so the hardening
 * cannot pass by simply rejecting more.
 */
$probe = [
   'error' => '',
   'legs' => [],
];

return new Test(
   description: 'HTTP/1 head must enforce tchar field-names, colon-bearing lines and its size cap',

   request: static function (string $hostPort, int $testIndex) use (&$probe): string {
      $Send = static function (string $raw) use ($hostPort): string {
         $socket = @stream_socket_client(
            "tcp://{$hostPort}", $errorNumber, $errorMessage, timeout: 5
         );
         if (! is_resource($socket)) {
            return "CONNECT-FAILED {$errorNumber} {$errorMessage}";
         }

         stream_set_blocking($socket, true);
         stream_set_timeout($socket, 3);
         @fwrite($socket, $raw);

         $response = '';
         $deadline = microtime(true) + 4.0;
         while (microtime(true) < $deadline) {
            $chunk = @fread($socket, 65535);
            if ($chunk === false || $chunk === '') {
               if (@feof($socket)) {
                  break;
               }

               $metadata = stream_get_meta_data($socket);
               if (($metadata['timed_out'] ?? false) === true) {
                  break;
               }
               continue;
            }
            $response .= $chunk;
            if (str_contains($response, "\r\n\r\n")) {
               break;
            }
         }
         @fclose($socket);

         return $response;
      };

      $Status = static function (string $response): int {
         if (preg_match('#^HTTP/1\.1 (\d{3})#', $response, $matches) === 1) {
            return (int) $matches[1];
         }

         return 0;
      };

      try {
         // ! N2 — a COMPLETE oversized head, delivered in a single write.
         $padding = str_repeat('A', 20000);
         $probe['legs']['n2_oversized_complete_head'] = $Status($Send(
            "GET /n-ok HTTP/1.1\r\nHost: localhost\r\n"
            . "X-Bootgly-Test: {$testIndex}\r\n"
            . "X-Pad: {$padding}\r\n"
            . "Connection: close\r\n\r\n"
         ));

         // ! N1 — a nonempty head line carrying no colon at all.
         $probe['legs']['n1_colonless_line'] = $Status($Send(
            "GET /n-ok HTTP/1.1\r\nHost: localhost\r\n"
            . "X-Bootgly-Test: {$testIndex}\r\n"
            . "ThisLineHasNoColon\r\n"
            . "Connection: close\r\n\r\n"
         ));

         // ! N1 — non-tchar bytes inside a framing field-name.
         $probe['legs']['n1_vtab_content_length'] = $Status($Send(
            "POST /n-ok HTTP/1.1\r\nHost: localhost\r\n"
            . "X-Bootgly-Test: {$testIndex}\r\n"
            . "Content-Length\x0B: 5\r\n"
            . "Connection: close\r\n\r\nHELLO"
         ));
         $probe['legs']['n1_nul_transfer_encoding'] = $Status($Send(
            "POST /n-ok HTTP/1.1\r\nHost: localhost\r\n"
            . "X-Bootgly-Test: {$testIndex}\r\n"
            . "Transfer-Encoding\x00: chunked\r\n"
            . "Connection: close\r\n\r\n"
         ));
         $probe['legs']['n1_separator_in_name'] = $Status($Send(
            "GET /n-ok HTTP/1.1\r\nHost: localhost\r\n"
            . "X-Bootgly-Test: {$testIndex}\r\n"
            . "Bad(Name): x\r\n"
            . "Connection: close\r\n\r\n"
         ));

         // ! L3 — raw C0/DEL octets in the request target.
         foreach ([
            'l3_target_nul' => "/n-ok\x00tail",
            'l3_target_vtab' => "/n-ok\x0Btail",
            'l3_target_del' => "/n-ok\x7Ftail",
         ] as $leg => $target) {
            $probe['legs'][$leg] = $Status($Send(
               "GET {$target} HTTP/1.1\r\nHost: localhost\r\n"
               . "X-Bootgly-Test: {$testIndex}\r\nConnection: close\r\n\r\n"
            ));
         }

         // ! L3 — HTAB in the request target, and control octets in a field
         //   VALUE (the name grammar alone does not cover them).
         $probe['legs']['l3_target_htab'] = $Status($Send(
            "GET /n-ok\tTAIL HTTP/1.1\r\nHost: localhost\r\n"
            . "X-Bootgly-Test: {$testIndex}\r\nConnection: close\r\n\r\n"
         ));
         foreach ([
            'l3_value_nul' => "a\x00b",
            'l3_value_vtab' => "a\x0Bb",
            'l3_value_del' => "a\x7Fb",
         ] as $leg => $value) {
            $probe['legs'][$leg] = $Status($Send(
               "GET /n-ok HTTP/1.1\r\nHost: localhost\r\n"
               . "X-Bootgly-Test: {$testIndex}\r\n"
               . "X-Probe: {$value}\r\n"
               . "Connection: close\r\n\r\n"
            ));
         }

         // ! L3 — chunk-extension grammar (RFC 9112 §7.1.1). The region used to
         //   be discarded unparsed, so every malformed form below still framed
         //   and dispatched body `A` — an intermediary that DOES parse it can
         //   disagree with Bootgly about where the body ends.
         $Chunked = static function (string $sizeLine) use ($Send, $Status, $testIndex): int {
            return $Status($Send(
               "POST /n-ok HTTP/1.1\r\nHost: localhost\r\n"
               . "X-Bootgly-Test: {$testIndex}\r\n"
               . "Transfer-Encoding: chunked\r\n"
               . "Connection: close\r\n\r\n"
               . "{$sizeLine}\r\nA\r\n0\r\n\r\n"
            ));
         };

         $probe['legs']['l3_ext_control_ok'] = $Chunked('1;foo=bar');
         $probe['legs']['l3_ext_control_quoted'] = $Chunked('1;foo="bar baz"');
         $probe['legs']['l3_ext_control_bare'] = $Chunked('1;foo');
         // ? RFC 9112 §7.1.1 imports BWS from RFC 9110 (`*(SP / HTAB)`)
         //   before/after `;` and `=`, while quoted-string also admits HTAB.
         $probe['legs']['l3_ext_bws_before_semicolon'] = $Chunked('1 ;foo=bar');
         $probe['legs']['l3_ext_htab_bws'] = $Chunked("1\t;\tfoo\t=\tbar");
         $probe['legs']['l3_ext_htab_quoted'] = $Chunked("1;foo=\"a\tb\"");
         $probe['legs']['l3_ext_htab_quoted_pair'] = $Chunked("1;foo=\"a\\\tb\"");
         $probe['legs']['l3_ext_empty_name'] = $Chunked('1;=x');
         $probe['legs']['l3_ext_unterminated'] = $Chunked('1;foo="unterminated');
         $probe['legs']['l3_ext_space_in_name'] = $Chunked('1;foo bar');
         $probe['legs']['l3_ext_missing_value'] = $Chunked('1;foo=');
         $probe['legs']['l3_ext_nul'] = $Chunked("1;bad\x00name=x");
         // ! BWS after a bare extension name is legal only when it introduces
         //   the optional `=` group or the next `;` extension. At end-of-line it
         //   is unmatched grammar and must not be silently discarded.
         $probe['legs']['l3_ext_trailing_bws'] = $Chunked('1;foo ');

         // ! Controls — ordinary request, RFC-valid no-space value, and a head
         //   sized EXACTLY at the 16384-byte limit.
         $probe['legs']['control_ordinary'] = $Status($Send(
            "GET /n-ok HTTP/1.1\r\nHost: localhost\r\n"
            . "X-Bootgly-Test: {$testIndex}\r\nConnection: close\r\n\r\n"
         ));
         $probe['legs']['control_no_space_value'] = $Status($Send(
            "GET /n-ok HTTP/1.1\r\nHost:localhost\r\n"
            . "X-Bootgly-Test: {$testIndex}\r\nConnection:close\r\n\r\n"
         ));

         $head = "GET /n-ok HTTP/1.1\r\nHost: localhost\r\n"
            . "X-Bootgly-Test: {$testIndex}\r\nConnection: close\r\nX-Pad: ";
         $tail = "\r\n\r\n";
         $fill = 16384 - strlen($head) - strlen($tail);
         $probe['legs']['control_exactly_at_limit'] = $fill > 0
            ? $Status($Send($head . str_repeat('B', $fill) . $tail))
            : 0;
      }
      catch (Throwable $Throwable) {
         $probe['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /n-harness HTTP/1.1\r\n"
         . "X-Bootgly-Test: {$testIndex}\r\n"
         . "Host: localhost\r\nConnection: close\r\n\r\n";
   },

   response: static function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/n-ok', static function (Request $Request, Response $Response) {
         return $Response(body: 'N-OK');
      });

      yield $Router->route('/n-harness', static function (Request $Request, Response $Response) {
         return $Response(body: 'HARNESS-OK');
      }, GET);

      yield $Router->route('/*', static function (Request $Request, Response $Response) {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: static function (string $response) use (&$probe): bool|string {
      if (! str_contains($response, 'HARNESS-OK')) {
         return 'N1/N2 harness request did not reach /n-harness.';
      }
      if ($probe['error'] !== '') {
         return 'N1/N2 fixture error: ' . $probe['error'];
      }

      $legs = $probe['legs'];

      // ? Controls first — the hardening must not simply reject more.
      foreach ([
         'control_ordinary',
         'control_no_space_value',
         'control_exactly_at_limit',
         // ! A well-formed chunk extension — token value, quoted-string value
         //   and bare name — must still frame its body.
         'l3_ext_control_ok',
         'l3_ext_control_quoted',
         'l3_ext_control_bare',
      ] as $control) {
         if (($legs[$control] ?? 0) !== 200) {
            return "N1/N2 control `{$control}` did not return 200 (got "
               . json_encode($legs[$control] ?? null)
               . '), so the rejection legs prove nothing: ' . json_encode($legs);
         }
      }

      $rejectedValid = [];
      foreach ([
         'l3_ext_bws_before_semicolon' => 'SP BWS before the first semicolon',
         'l3_ext_htab_bws' => 'HTAB BWS around extension delimiters',
         'l3_ext_htab_quoted' => 'HTAB qdtext in a quoted-string',
         'l3_ext_htab_quoted_pair' => 'an escaped HTAB quoted-pair',
      ] as $leg => $label) {
         if (($legs[$leg] ?? 0) !== 200) {
            $rejectedValid[] = "{$label} → "
               . json_encode($legs[$leg] ?? null) . ' (expected 200)';
         }
      }
      if ($rejectedValid !== []) {
         return 'CONFIRMED L3: the chunk-extension parser rejected RFC-valid grammar — '
            . implode('; ', $rejectedValid)
            . '. This is an HTTP/1.1 interoperability and request-availability gap.';
      }

      $accepted = [];
      if (($legs['n2_oversized_complete_head'] ?? 0) !== 413) {
         $accepted[] = 'N2 complete 20,046-byte head → ' . json_encode($legs['n2_oversized_complete_head'] ?? null)
            . ' (expected 413)';
      }
      foreach ([
         'n1_colonless_line' => 'a head line with no colon',
         'n1_vtab_content_length' => "Content-Length\\x0B",
         'n1_nul_transfer_encoding' => "Transfer-Encoding\\x00",
         'n1_separator_in_name' => 'Bad(Name)',
         'l3_target_nul' => 'a NUL byte in the request target',
         'l3_target_vtab' => 'a vertical tab in the request target',
         'l3_target_del' => 'a DEL byte in the request target',
         'l3_target_htab' => 'a horizontal tab in the request target',
         'l3_value_nul' => "a NUL byte in a field value",
         'l3_value_vtab' => 'a vertical tab in a field value',
         'l3_value_del' => 'a DEL byte in a field value',
         'l3_ext_empty_name' => 'a chunk extension with an empty name (`;=x`)',
         'l3_ext_unterminated' => 'an unterminated quoted chunk-extension value',
         'l3_ext_space_in_name' => 'a space inside a chunk-extension name',
         'l3_ext_missing_value' => 'a chunk extension with `=` and no value',
         'l3_ext_nul' => 'a NUL byte inside a chunk extension',
         'l3_ext_trailing_bws' => 'unmatched trailing BWS after a bare chunk extension',
      ] as $leg => $label) {
         if (($legs[$leg] ?? 0) !== 400) {
            $accepted[] = "N1 {$label} → " . json_encode($legs[$leg] ?? null) . ' (expected 400)';
         }
      }

      if ($accepted !== []) {
         return 'CONFIRMED N1/N2/L3: the HTTP/1 head parser accepted forms it must reject — '
            . implode('; ', $accepted)
            . '. Each is a parser-differential primitive: another HTTP recipient may reject '
            . 'or delimit the same request differently.';
      }

      return true;
   },
);
