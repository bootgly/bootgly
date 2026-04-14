<?php
/**
 * CacheIsolation: GET /other-headers → response with DIFFERENT headers.
 * Verify headers from previous test (X-Custom-A, Cache-Control) are NOT present.
 */

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test\Specification;

return new Specification(
   description: 'It should not leak headers from prior response',

   response: function () {
      return "HTTP/1.1 200 OK\r\nX-Custom-B: value-b\r\nX-Request-Id: req-002\r\nContent-Length: 1\r\nConnection: close\r\n\r\nB";
   },

   request: function (HTTP_Client_CLI $Client): Response {
      return $Client->request(method: 'GET', URI: '/other-headers');
   },

   test: function (Response $Response) {
      yield assert(
         assertion: $Response->Header->get('X-Custom-B') === 'value-b',
         description: "Has X-Custom-B: " . $Response->Header->get('X-Custom-B')
      );
      yield assert(
         assertion: $Response->Header->get('X-Request-Id') === 'req-002',
         description: "X-Request-Id is req-002 (not req-001): " . $Response->Header->get('X-Request-Id')
      );
      yield assert(
         assertion: $Response->Header->get('X-Custom-A') === null,
         description: "X-Custom-A is absent (not leaked): " . ($Response->Header->get('X-Custom-A') ?? 'null')
      );
      yield assert(
         assertion: $Response->Header->get('Cache-Control') === null,
         description: "Cache-Control is absent (not leaked): " . ($Response->Header->get('Cache-Control') ?? 'null')
      );
   }
);
