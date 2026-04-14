<?php
/**
 * CacheIsolation: GET /with-headers → response with unique headers.
 * Primes header fields that must NOT appear in the next test.
 */

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test\Specification;

return new Specification(
   description: 'It should have X-Custom-A and Cache-Control headers',

   response: function () {
      return "HTTP/1.1 200 OK\r\nX-Custom-A: value-a\r\nX-Request-Id: req-001\r\nCache-Control: no-cache\r\nContent-Length: 1\r\nConnection: close\r\n\r\nA";
   },

   request: function (HTTP_Client_CLI $Client): Response {
      return $Client->request(method: 'GET', URI: '/with-headers');
   },

   test: function (Response $Response) {
      yield assert(
         assertion: $Response->Header->get('X-Custom-A') === 'value-a',
         description: "Has X-Custom-A: " . $Response->Header->get('X-Custom-A')
      );
      yield assert(
         assertion: $Response->Header->get('X-Request-Id') === 'req-001',
         description: "X-Request-Id is req-001: " . $Response->Header->get('X-Request-Id')
      );
      yield assert(
         assertion: $Response->Header->get('Cache-Control') === 'no-cache',
         description: "Has Cache-Control: " . $Response->Header->get('Cache-Control')
      );
   }
);
