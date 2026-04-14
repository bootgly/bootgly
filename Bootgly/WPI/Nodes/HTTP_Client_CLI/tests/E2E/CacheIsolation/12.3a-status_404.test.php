<?php
/**
 * CacheIsolation: GET /missing → 404 "Not Found".
 * Verify 404 status code is returned correctly after prior 200/201 responses.
 */

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test\Specification;

return new Specification(
   description: 'It should return 404 (not leak 200/201 from prior responses)',

   response: function () {
      return "HTTP/1.1 404 Not Found\r\nContent-Type: text/plain\r\nContent-Length: 9\r\nConnection: close\r\n\r\nNot Found";
   },

   request: function (HTTP_Client_CLI $Client): Response {
      return $Client->request(method: 'GET', URI: '/missing');
   },

   test: function (Response $Response) {
      yield assert(
         assertion: $Response->code === 404,
         description: "Status is 404 (not leaked 200): {$Response->code}"
      );
      yield assert(
         assertion: $Response->status === 'Not Found',
         description: "Status text is 'Not Found': '{$Response->status}'"
      );
      yield assert(
         assertion: $Response->Body->raw === 'Not Found',
         description: "Body is 'Not Found': '{$Response->Body->raw}'"
      );
      yield assert(
         assertion: $Response->Body->length === 9,
         description: "Content-Length is 9: {$Response->Body->length}"
      );
   }
);
