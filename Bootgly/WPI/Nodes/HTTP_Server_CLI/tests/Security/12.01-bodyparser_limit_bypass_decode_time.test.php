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
 * This PoC:
 *   1. Opens a side connection and sends a 200-byte POST (priming).
 *      BodyParser catches it at middleware time → shaped 413
 *      (Server: Bootgly, body "Payload Too Large") → pushes limit.
 *   2. Harness sends the same 200-byte POST on its own connection.
 *      Decoder gate catches it → bare 413 → connection closed.
 *   3. Test verifies the harness got the bare 413 (no "Payload Too
 *      Large" body), proving the decoder-level limit was lowered.
 */

$primingResponse = '';

return new Specification(
   description: 'BodyParser must push maxSize into Request::$maxBodySize for decode-time enforcement',
   Separator: new Separator(line: true),

   middlewares: [new BodyParser(maxSize: 100)],

   request: function (string $hostPort) use (&$primingResponse): string {
      $oversizedBody = str_repeat('X', 200);
      $primingBytes = "POST /bodyparser-poc HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Content-Type: text/plain\r\n"
         . "Content-Length: 200\r\n"
         . "Connection: close\r\n"
         . "\r\n"
         . $oversizedBody;

      // ! Priming request: opens a side connection to trigger BodyParser
      //   process(), which pushes maxSize into Request::$maxBodySize.
      $priming = @\stream_socket_client(
         "tcp://{$hostPort}", $errno, $errstr, timeout: 5
      );
      if (\is_resource($priming)) {
         \stream_set_blocking($priming, true);
         \stream_set_timeout($priming, 2);
         \fwrite($priming, $primingBytes);

         $deadline = \microtime(true) + 2.0;
         $buf = '';
         while (\microtime(true) < $deadline) {
            $chunk = @\fread($priming, 65535);
            if ($chunk === false || $chunk === '') {
               if (@\feof($priming) || str_contains($buf, "\r\n\r\n")) break;
               continue;
            }
            $buf .= $chunk;
            if (str_contains($buf, "\r\n\r\n")) break;
         }
         $primingResponse = $buf;
         @\fclose($priming);
      }

      // : Harness sends the same oversized POST on its own connection.
      //   If the fix works, the decoder catches this with a bare 413.
      return "POST /bodyparser-poc HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Content-Type: text/plain\r\n"
         . "Content-Length: 200\r\n"
         . "Connection: close\r\n"
         . "\r\n"
         . $oversizedBody;
   },

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

   test: function ($response) use (&$primingResponse): bool|string {
      if (! \is_string($response) || $response === '') {
         return 'No response from server (harness request).';
      }

      // @ Verify priming request was caught by BodyParser middleware
      //   (shaped 413 with "Payload Too Large" body).
      if (! str_contains($primingResponse, 'Payload Too Large')) {
         return 'Priming request did not receive BodyParser middleware 413. '
            . 'Got: ' . substr($primingResponse, 0, 200);
      }

      // @ Verify harness request was caught at decode-time (bare 413,
      //   no "Payload Too Large" body, no "Server: Bootgly" header).
      if (str_contains($response, 'HANDLER-REACHED')) {
         return 'Handler was reached — BodyParser limit was not pushed '
            . 'to decode-time gate. The oversized body was fully buffered.';
      }

      if (str_contains($response, 'Payload Too Large')) {
         return 'Harness request was caught at middleware time (shaped 413), '
            . 'not at decode time. The decoder-level limit was not lowered. '
            . 'Response: ' . substr($response, 0, 200);
      }

      if (! str_contains($response, '413')) {
         return 'Expected 413 response, got: ' . substr($response, 0, 200);
      }

      // @ A bare "413 Request Entity Too Large\r\n\r\n" (no body, no
      //   Server header) means the decoder gate caught it. Success.
      return true;
   }
);
