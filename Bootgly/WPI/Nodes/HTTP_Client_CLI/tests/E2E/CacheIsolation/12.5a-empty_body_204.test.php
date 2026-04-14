<?php
/**
 * CacheIsolation: GET /empty → 204 No Content.
 * Verify empty body after previous responses with content.
 */

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test\Specification;

return new Specification(
   description: 'It should return empty body for 204 (no leftover content)',

   response: function () {
      return "HTTP/1.1 204 No Content\r\nConnection: close\r\n\r\n";
   },

   request: function (HTTP_Client_CLI $Client): Response {
      return $Client->request(method: 'GET', URI: '/empty');
   },

   test: function (Response $Response) {
      yield assert(
         assertion: $Response->code === 204,
         description: "Status is 204: {$Response->code}"
      );
      yield assert(
         assertion: $Response->Body->raw === '',
         description: "Body is empty (no leftover): '{$Response->Body->raw}'"
      );
      yield assert(
         assertion: $Response->Body->length === 0,
         description: "Content-Length is 0: {$Response->Body->length}"
      );
   }
);
