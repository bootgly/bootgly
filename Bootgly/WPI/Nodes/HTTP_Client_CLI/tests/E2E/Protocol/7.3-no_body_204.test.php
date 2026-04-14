<?php

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test\Specification;

return new Specification(
   description: 'It should handle 204 No Content with no body',

   // HTTP response: 204 with no body (RFC 9112 §6.3)
   response: function () {
      return "HTTP/1.1 204 No Content\r\nX-Custom: header\r\nConnection: close\r\n\r\n";
   },

   // Closure that triggers DELETE request
   request: function (HTTP_Client_CLI $Client): Response {
      return $Client->request(
         method: 'DELETE',
         URI: '/resource/123'
      );
   },

   // Test: 204 response should have empty body
   test: function (Response $Response) {
      // @ Validate status code
      yield assert(
         assertion: $Response->code === 204,
         description: "Status code is 204: {$Response->code}"
      );

      // @ Validate body is empty (RFC 9112 §6.3: 204 MUST NOT have body)
      yield assert(
         assertion: $Response->Body->raw === '',
         description: "Body is empty for 204"
      );

      yield assert(
         assertion: $Response->Body->length === 0,
         description: "Body length is 0 for 204"
      );

      // @ Headers should still be parsed
      yield assert(
         assertion: $Response->Header->get('X-Custom') === 'header',
         description: "Custom header parsed: " . $Response->Header->get('X-Custom')
      );
   }
);
