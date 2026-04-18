<?php

use function str_contains;

use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * PoC (b) — Expect + oversized Content-Length.
 *   The oversize check IS performed later in the non-chunked branch, but
 *   the `100 Continue` intermission has ALREADY been written on the wire
 *   before the `413` is emitted — wasted syscalls, confusing intermediary
 *   state. RFC 9110 §10.1.1 requires the server to NOT write `100
 *   Continue` once it has decided to reject.
 *
 * Fixed behaviour: the decoder validates `content_length <= maxFileSize`
 * BEFORE emitting `100 Continue`; oversized CL goes straight to `413`.
 */

return new Specification(
   description: 'Request::decode() must not send 100 Continue before oversized CL is rejected',
   Separator: new Separator(line: true),

   request: function (): string {
      // @ `999999999999` is well above both maxBodySize (10 MB) and
      //   maxFileSize (500 MB) so the 413 branch fires regardless of
      //   multipart detection.
      return "POST /expect-cl HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Content-Length: 999999999999\r\n"
         . "Expect: 100-continue\r\n"
         . "Connection: close\r\n"
         . "\r\n";
   },

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/*', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'HANDLER-REACHED');
      });
   },

   test: function ($response): bool|string {
      if (! \is_string($response) || $response === '') {
         return 'No response from server.';
      }

      if (str_contains($response, 'HANDLER-REACHED')) {
         return 'Handler was reached — oversized Content-Length must be '
            . 'rejected at decode time.';
      }

      if (str_contains($response, '100 Continue')) {
         return 'Server wrote "100 Continue" before rejecting the oversized '
            . 'Content-Length. Fix: validate content_length <= maxFileSize '
            . 'BEFORE emitting the 100 Continue interim response.';
      }

      return true;
   }
);
