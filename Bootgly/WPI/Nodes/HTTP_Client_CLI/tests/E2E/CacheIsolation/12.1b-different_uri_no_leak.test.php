<?php
/**
 * CacheIsolation: after GET /alpha (12.1a), request GET /beta.
 * Verify body, headers, and Content-Type are NOT leaked from /alpha.
 */

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test\Specification;

return new Specification(
   description: 'It should not leak body/headers from previous /alpha to /beta',

   response: function () {
      return "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nX-Route: beta\r\nContent-Length: 8\r\nConnection: close\r\n\r\nBetaBeta";
   },

   request: function (HTTP_Client_CLI $Client): Response {
      return $Client->request(method: 'GET', URI: '/beta');
   },

   test: function (Response $Response) {
      yield assert(
         assertion: $Response->Body->raw === 'BetaBeta',
         description: "Body is 'BetaBeta' (not 'Alpha'): '{$Response->Body->raw}'"
      );
      yield assert(
         assertion: $Response->Body->length === 8,
         description: "Content-Length is 8 (not 5): {$Response->Body->length}"
      );
      yield assert(
         assertion: $Response->Header->get('X-Route') === 'beta',
         description: "X-Route is 'beta' (not 'alpha'): " . $Response->Header->get('X-Route')
      );
      yield assert(
         assertion: $Response->Header->get('Content-Type') === 'application/json',
         description: "Content-Type is 'application/json' (not 'text/plain'): " . $Response->Header->get('Content-Type')
      );
   }
);
