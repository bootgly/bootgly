<?php

use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test\Specification;

return new Specification(
   Separator: new Separator(line: 'Protocol'),
   description: 'It should handle Expect: 100-continue flow',

   // HTTP response: final 200 response (100 Continue is sent by mock server automatically)
   response: function () {
      return "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: 8\r\nConnection: close\r\n\r\nReceived";
   },

   // Closure that triggers the HTTP client request with Expect header
   request: function (HTTP_Client_CLI $Client): Response {
      return $Client->request(
         method: 'POST',
         URI: '/upload',
         headers: [
            'Expect' => '100-continue',
            'Content-Type' => 'application/octet-stream'
         ],
         body: 'Large file content here...'
      );
   },

   // Test closure receives the final decoded Response object (not interim)
   test: function (Response $Response) {
      // @ Validate final status code (not 100)
      yield assert(
         assertion: $Response->code === 200,
         description: "Final status code is 200 (not 100): {$Response->code}"
      );

      // @ Validate body
      yield assert(
         assertion: $Response->Body->raw === 'Received',
         description: "Final body: {$Response->Body->raw}"
      );

      // @ Validate protocol
      yield assert(
         assertion: $Response->protocol === 'HTTP/1.1',
         description: "Protocol is HTTP/1.1"
      );
   }
);
