<?php

use function str_contains;
use function substr;

use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * PoC — Audit F-6 (size cap): the chunked total-size cap must gate on the
 * configurable `Request::$maxBodySize` (the `requestMaxBodySize` knob), not a
 * private 10 MB constant. Here a chunk declaring 16 MiB exceeds the default
 * 10 MB cap and must be rejected `413 Request Entity Too Large` on the declared
 * chunk-size, before any body data is read.
 */
return new Specification(
   description: 'Chunked body declaring more than Request::$maxBodySize is rejected 413',
   Separator: new Separator(line: true),

   request: function (): string {
      // 0x1000000 = 16 MiB chunk declared, with no data — the size cap fires
      // on the chunk-size line before the decoder waits for the body.
      return "POST /f6-chunked-big HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Transfer-Encoding: chunked\r\n"
         . "Connection: close\r\n"
         . "\r\n"
         . "1000000\r\n";
   },

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/f6-chunked-big', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'SHOULD-NOT-REACH');
      }, POST);
      yield $Router->route('/*', function (Request $Request, Response $Response) {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: function ($response): bool|string {
      if (! \is_string($response) || $response === '') {
         return true; // connection closed before any 200 — request was rejected
      }

      if (str_contains($response, 'SHOULD-NOT-REACH')) {
         return 'Oversized chunked body was dispatched — the chunked size cap '
            . 'must gate on Request::$maxBodySize and reject with 413.';
      }

      if (str_contains($response, '413')) {
         return true;
      }

      return 'Unexpected response (expected 413 Request Entity Too Large): '
         . substr($response, 0, 200);
   }
);
