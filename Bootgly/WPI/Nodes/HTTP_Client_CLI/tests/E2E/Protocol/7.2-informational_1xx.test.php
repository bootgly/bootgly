<?php

use Generator;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test\Specification;

return new Specification(
   description: 'It should skip 1xx informational responses and return final',

   // HTTP response: 102 Processing (interim) followed by 200 OK (final)
   response: function (): Generator {
      yield "HTTP/1.1 102 Processing\r\n\r\n";
      yield "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: 4\r\nConnection: close\r\n\r\nDone";
   },

   // Closure that triggers the HTTP client request
   request: function (HTTP_Client_CLI $Client): Response {
      return $Client->request(
         method: 'GET',
         URI: '/long-operation'
      );
   },

   // Test: should receive ONLY the final 200 response, not 102
   test: function (Response $Response) {
      // @ Validate that 1xx was skipped and final is 200
      yield assert(
         assertion: $Response->code === 200,
         description: "Final status code is 200 (102 skipped): {$Response->code}"
      );

      yield assert(
         assertion: $Response->Body->raw === 'Done',
         description: "Final body: {$Response->Body->raw}"
      );
   }
);
