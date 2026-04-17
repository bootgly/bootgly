<?php

use function str_contains;

use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * PoC — `Request::decode()` writes `HTTP/1.1 100 Continue\r\n\r\n` the
 * moment it sees `Expect: 100-continue`, BEFORE any body-size / transfer
 * form gate is evaluated.
 *
 * This case (a): Expect + Transfer-Encoding: chunked.
 *   `content_length` is not set on the chunked path, so the oversize guard
 *   (`$length > static::$maxFileSize`) never runs for TE. The server
 *   commits Decoder_Chunked (MAX_BODY_SIZE = 10 MB per connection) without
 *   the application ever seeing the request — memory/CPU amplification.
 *
 * Fixed behaviour: no `100 Continue` interim; `417 Expectation Failed`
 * instead (Expect + TE-chunked without application consent per RFC 9110
 * §10.1.1 remediation).
 *
 * Observed on the wire by reading raw bytes from the harness connection
 * for a short window and grepping for the interim status line.
 */

return new Specification(
   description: 'Request::decode() must not send 100 Continue for Expect + TE-chunked',
   Separator: new Separator(line: true),

   // @ Special-case request: harness sends headers only (no chunk body),
   //   then the test runner reads as many bytes as the server emits. A
   //   vulnerable server emits `HTTP/1.1 100 Continue\r\n\r\n` immediately
   //   and then hangs waiting for the body; a fixed server emits
   //   `HTTP/1.1 417 Expectation Failed\r\n\r\n` and closes.
   request: function (): string {
      return "POST /expect-te HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Transfer-Encoding: chunked\r\n"
         . "Expect: 100-continue\r\n"
         . "Connection: close\r\n"
         . "\r\n";
   },

   response: function (Request $Request, Response $Response, Router $Router) {
      // @ Handler is unreachable for the vulnerable path (chunked body
      //   never arrives), and also unreachable for the fixed path (417 is
      //   emitted by the decoder). Route is only here to satisfy the
      //   response queue consumer.
      yield $Router->route('/*', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'HANDLER-REACHED');
      });
   },

   test: function ($response): bool|string {
      if (! \is_string($response) || $response === '') {
         return 'No response from server.';
      }

      if (str_contains($response, 'HANDLER-REACHED')) {
         return 'Handler was reached — Expect + TE-chunked must short-circuit '
            . 'at decode time, not dispatch.';
      }

      if (str_contains($response, '100 Continue')) {
         return 'Server wrote "100 Continue" for Expect + Transfer-Encoding: '
            . 'chunked without application consent. Decoder_Chunked is now '
            . 'committed to streaming up to MAX_BODY_SIZE (10 MB) per '
            . 'connection. Fix: reject with 417 Expectation Failed per RFC '
            . '9110 §10.1.1 remediation.';
      }

      return true;
   }
);
