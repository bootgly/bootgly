<?php
/**
 * CacheIsolation: GET /final → 200 "End".
 * Verify body is present after prior 204 empty response.
 */

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test\Specification;

return new Specification(
   description: 'It should return body after prior 204 empty response',

   response: function () {
      return "HTTP/1.1 200 OK\r\nContent-Length: 3\r\nConnection: close\r\n\r\nEnd";
   },

   request: function (HTTP_Client_CLI $Client): Response {
      return $Client->request(method: 'GET', URI: '/final');
   },

   test: function (Response $Response) {
      yield assert(
         assertion: $Response->code === 200,
         description: "Status is 200 (not 204): {$Response->code}"
      );
      yield assert(
         assertion: $Response->Body->raw === 'End',
         description: "Body is 'End' (not empty): '{$Response->Body->raw}'"
      );
      yield assert(
         assertion: $Response->Body->length === 3,
         description: "Content-Length is 3 (not 0): {$Response->Body->length}"
      );
   }
);
