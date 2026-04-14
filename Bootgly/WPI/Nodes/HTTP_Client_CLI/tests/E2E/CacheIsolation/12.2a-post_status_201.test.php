<?php
/**
 * CacheIsolation: POST /resource → 201 "created!".
 * Verify method-specific response (201 vs 200) is not confused with prior GETs.
 */

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test\Specification;

return new Specification(
   description: 'It should return 201 for POST (not leak 200 from prior GETs)',

   response: function (string $input) {
      $method = strstr($input, ' ', true);
      return "HTTP/1.1 201 Created\r\nContent-Type: application/json\r\nX-Method: {$method}\r\nContent-Length: 8\r\nConnection: close\r\n\r\ncreated!";
   },

   request: function (HTTP_Client_CLI $Client): Response {
      return $Client->request(method: 'POST', URI: '/resource', body: 'payload');
   },

   test: function (Response $Response) {
      yield assert(
         assertion: $Response->code === 201,
         description: "Status is 201 (not 200 from prior GETs): {$Response->code}"
      );
      yield assert(
         assertion: $Response->status === 'Created',
         description: "Status text is 'Created': '{$Response->status}'"
      );
      yield assert(
         assertion: $Response->Body->raw === 'created!',
         description: "Body is 'created!': '{$Response->Body->raw}'"
      );
      yield assert(
         assertion: $Response->Header->get('X-Method') === 'POST',
         description: "Echoed method is POST: " . $Response->Header->get('X-Method')
      );
   }
);
