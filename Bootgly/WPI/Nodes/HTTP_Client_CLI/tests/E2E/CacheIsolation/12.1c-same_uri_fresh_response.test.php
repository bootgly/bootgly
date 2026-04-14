<?php
/**
 * CacheIsolation: request GET /alpha again (same URI as 12.1a).
 * Server returns DIFFERENT content this time.
 * Verify decoder cache doesn't replay stale body/headers from 12.1a.
 */

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test\Specification;

return new Specification(
   description: 'It should not replay stale cached response for same URI',

   response: function () {
      return "HTTP/1.1 200 OK\r\nContent-Type: text/html\r\nX-Route: alpha-v2\r\nContent-Length: 7\r\nConnection: close\r\n\r\nAlphaV2";
   },

   request: function (HTTP_Client_CLI $Client): Response {
      return $Client->request(method: 'GET', URI: '/alpha');
   },

   test: function (Response $Response) {
      yield assert(
         assertion: $Response->Body->raw === 'AlphaV2',
         description: "Body is 'AlphaV2' (not stale 'Alpha'): '{$Response->Body->raw}'"
      );
      yield assert(
         assertion: $Response->Body->length === 7,
         description: "Content-Length is 7 (not stale 5): {$Response->Body->length}"
      );
      yield assert(
         assertion: $Response->Header->get('X-Route') === 'alpha-v2',
         description: "X-Route is 'alpha-v2' (not stale 'alpha'): " . $Response->Header->get('X-Route')
      );
      yield assert(
         assertion: $Response->Header->get('Content-Type') === 'text/html',
         description: "Content-Type is 'text/html' (not stale 'text/plain'): " . $Response->Header->get('Content-Type')
      );
   }
);
