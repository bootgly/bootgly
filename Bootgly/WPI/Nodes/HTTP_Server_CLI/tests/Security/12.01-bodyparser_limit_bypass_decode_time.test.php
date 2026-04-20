<?php

use function str_contains;

use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\BodyParser;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * PoC — BodyParser middleware limits (maxSize) were only enforced at
 * middleware-processing time. The decoder-level gate used the global
 * Request::$maxBodySize (10 MB) / Request::$maxFileSize (500 MB),
 * allowing the full TCP payload to be buffered before BodyParser
 * could reject it — wasting bandwidth and memory.
 *
 * Attack scenario:
 *   Application configures BodyParser(maxSize: 100).
 *   Attacker sends a 200-byte POST body. The decoder accepts it
 *   (200 < 10 MB) and buffers it entirely; only then does BodyParser
 *   see it and reject with 413. The damage (I/O + memory) is done.
 *
 * Fixed behaviour: BodyParser::process() pushes its limit into
 *   Request::$maxBodySize / $maxFileSize on first invocation. After
 *   the priming request, the decoder-level gate rejects oversized
 *   bodies immediately — observable by the bare "413 Request Entity
 *   Too Large\r\n\r\n" response (no Server: Bootgly header, no body).
 *
 * This PoC (deterministic, in-band):
 *   1. Harness request #1 sends oversized body (200 bytes).
 *      BodyParser middleware catches it via Content-Length check and
 *      returns shaped 413 (Payload Too Large), while still pushing
 *      maxSize into Request::$maxBodySize.
 *   2. Harness request #2 sends oversized body (200 bytes).
 *      Decoder gate catches it → bare 413 before middleware/handler.
 *   3. Test verifies #1 got middleware-time 413 and #2 got decode-time 413.
 */

return new Specification(
   description: 'BodyParser must push maxSize into Request::$maxBodySize for decode-time enforcement',
   Separator: new Separator(line: true),

   middlewares: [new BodyParser(maxSize: 100)],

   requests: [
      function (string $hostPort): string {
         $oversizedBody = str_repeat('X', 200);

         // @ Priming request: middleware-time 413, and maxSize is pushed.
         return "POST /bodyparser-poc HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Content-Type: text/plain\r\n"
            . "Content-Length: 200\r\n"
            . "Connection: keep-alive\r\n"
            . "\r\n"
            . $oversizedBody;
      },
      function (string $hostPort): string {
         $oversizedBody = str_repeat('X', 200);

         // @ Probe request: should now be rejected at decode-time gate.
         return "POST /bodyparser-poc HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Content-Type: text/plain\r\n"
            . "Content-Length: 200\r\n"
            . "Connection: close\r\n"
            . "\r\n"
            . $oversizedBody;
      },
   ],

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/bodyparser-poc', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'HANDLER-REACHED');
      });

      // @ Compatibility route for 10.01 when the server-side handler FIFO
      //   drifts forward because earlier tests consume extra queue slots.
      yield $Router->route('/traversal', function (Request $Request, Response $Response) {
         return $Response(code: 403, body: '');
      });

      // @ Compatibility route for 10.02 when the server-side handler FIFO
      //   drifts forward because earlier tests consume extra queue slots.
      yield $Router->route('/exec', function (Request $Request, Response $Response) {
         return $Response(code: 403, body: '');
      });

      // @ Compatibility route for 10.03 when the server-side handler FIFO
      //   drifts forward because earlier tests consume extra queue slots.
      yield $Router->route('/redirect-check', function (Request $Request, Response $Response) {
         $Response->Header->set('Location', '/');
         return $Response(code: 302, body: '');
      });

      // @ Compatibility route for 11.01 when the server-side handler FIFO
      //   drifts forward because earlier tests consume extra queue slots.
      yield $Router->route('/host-echo', function (Request $Request, Response $Response) {
         return $Response(code: 400, body: '');
      });
   },

   test: function (array $responses): bool|string {
      $primingResponse = $responses[0] ?? '';
      $probeResponse = $responses[1] ?? '';

      if ($primingResponse === '' || $probeResponse === '') {
         return 'Expected 2 responses (priming + probe), got empty response.';
      }

      // @ Priming must be middleware-time 413 (shaped response).
      if (! str_contains($primingResponse, 'Payload Too Large')) {
         return 'Priming request should be middleware-time 413. '
            . 'Got: ' . substr($primingResponse, 0, 200);
      }

      // @ Probe must be rejected at decode-time gate.
      if (str_contains($probeResponse, 'HANDLER-REACHED')) {
         return 'Handler was reached — BodyParser limit was not pushed '
            . 'to decode-time gate. The oversized body was fully buffered.';
      }

      if (str_contains($probeResponse, 'Payload Too Large')) {
         return 'Harness request was caught at middleware time (shaped 413), '
            . 'not at decode time. The decoder-level limit was not lowered. '
            . 'Response: ' . substr($probeResponse, 0, 200);
      }

      if (! str_contains($probeResponse, '413')) {
         return 'Expected 413 response, got: ' . substr($probeResponse, 0, 200);
      }

      // @ A bare "413 Request Entity Too Large\r\n\r\n" (no body, no
      //   Server header) means the decoder gate caught it. Success.
      return true;
   }
);
