<?php
/**
 * CacheIsolation: GET /resource → 200 "get-data".
 * After prior POST that returned 201, verify GET returns 200 (no status leak).
 */

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test\Specification;

return new Specification(
   description: 'It should return 200 for GET (not leak 201 from prior POST)',

   response: function (string $input) {
      $method = strstr($input, ' ', true);
      return "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nX-Method: {$method}\r\nContent-Length: 8\r\nConnection: close\r\n\r\nget-data";
   },

   request: function (HTTP_Client_CLI $Client): Response {
      return $Client->request(method: 'GET', URI: '/resource');
   },

   test: function (Response $Response) {
      yield assert(
         assertion: $Response->code === 200,
         description: "Status is 200 (not 201 from prior POST): {$Response->code}"
      );
      yield assert(
         assertion: $Response->Body->raw === 'get-data',
         description: "Body is 'get-data' (not 'created!'): '{$Response->Body->raw}'"
      );
      yield assert(
         assertion: $Response->Header->get('X-Method') === 'GET',
         description: "Echoed method is GET: " . $Response->Header->get('X-Method')
      );
      yield assert(
         assertion: $Response->Header->get('Content-Type') === 'text/plain',
         description: "Content-Type is 'text/plain' (not 'application/json'): " . $Response->Header->get('Content-Type')
      );
   }
);
