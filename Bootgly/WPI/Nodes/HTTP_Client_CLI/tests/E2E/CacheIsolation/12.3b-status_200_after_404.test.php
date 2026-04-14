<?php
/**
 * CacheIsolation: GET /ok → 200 "Recovery".
 * Verify status recovers to 200 after prior 404 response.
 */

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test\Specification;

return new Specification(
   description: 'It should return 200 after prior 404 (no status leak)',

   response: function () {
      return "HTTP/1.1 200 OK\r\nContent-Type: text/html\r\nContent-Length: 8\r\nConnection: close\r\n\r\nRecovery";
   },

   request: function (HTTP_Client_CLI $Client): Response {
      return $Client->request(method: 'GET', URI: '/ok');
   },

   test: function (Response $Response) {
      yield assert(
         assertion: $Response->code === 200,
         description: "Status is 200 (not leaked 404): {$Response->code}"
      );
      yield assert(
         assertion: $Response->Body->raw === 'Recovery',
         description: "Body is 'Recovery': '{$Response->Body->raw}'"
      );
      yield assert(
         assertion: $Response->Header->get('Content-Type') === 'text/html',
         description: "Content-Type is 'text/html': " . $Response->Header->get('Content-Type')
      );
   }
);
