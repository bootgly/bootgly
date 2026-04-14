<?php
/**
 * CacheIsolation sequence: prime decoder cache with GET /alpha → 200 "Alpha".
 * Next test (12.1b) will request a different URI and verify no contamination.
 */

use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test\Specification;

return new Specification(
   Separator: new Separator(line: 'CacheIsolation'),
   description: 'It should return correct body for GET /alpha (prime cache)',

   response: function () {
      return "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nX-Route: alpha\r\nContent-Length: 5\r\nConnection: close\r\n\r\nAlpha";
   },

   request: function (HTTP_Client_CLI $Client): Response {
      return $Client->request(method: 'GET', URI: '/alpha');
   },

   test: function (Response $Response) {
      yield assert(
         assertion: $Response->code === 200,
         description: "Status code is 200: {$Response->code}"
      );
      yield assert(
         assertion: $Response->Body->raw === 'Alpha',
         description: "Body is 'Alpha': '{$Response->Body->raw}'"
      );
      yield assert(
         assertion: $Response->Body->length === 5,
         description: "Content-Length is 5: {$Response->Body->length}"
      );
      yield assert(
         assertion: $Response->Header->get('X-Route') === 'alpha',
         description: "X-Route is 'alpha': " . $Response->Header->get('X-Route')
      );
      yield assert(
         assertion: $Response->Header->get('Content-Type') === 'text/plain',
         description: "Content-Type is 'text/plain': " . $Response->Header->get('Content-Type')
      );
   }
);
