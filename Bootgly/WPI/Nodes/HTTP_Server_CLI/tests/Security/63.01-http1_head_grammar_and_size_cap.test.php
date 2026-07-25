<?php

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


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

return new Specification(
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
      ] as $control) {
         if (($legs[$control] ?? 0) !== 200) {
            return "N1/N2 control `{$control}` did not return 200 (got "
               . json_encode($legs[$control] ?? null)
               . '), so the rejection legs prove nothing: ' . json_encode($legs);
         }
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
      ] as $leg => $label) {
         if (($legs[$leg] ?? 0) !== 400) {
            $accepted[] = "N1 {$label} → " . json_encode($legs[$leg] ?? null) . ' (expected 400)';
         }
      }

      if ($accepted !== []) {
         return 'CONFIRMED N1/N2: the HTTP/1 head parser accepted forms it must reject — '
            . implode('; ', $accepted)
            . '. Each is a parser-differential primitive: Bootgly ignores the disguised field '
            . 'while a tolerant intermediary may still read it.';
      }

      return true;
   },
);
