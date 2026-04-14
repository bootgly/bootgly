<?php

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test\Specification;

return new Specification(
   description: 'It should handle chunked transfer-encoding over TLS',

   // HTTP response with chunked encoding
   response: function () {
      return "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n5\r\nHello\r\n7\r\n, TLS!\n\r\n0\r\n\r\n";
   },

   // Closure that triggers the HTTP client request
   request: function (HTTP_Client_CLI $Client): Response {
      return $Client->request(
         method: 'GET',
         URI: '/chunked'
      );
   },

   // Test closure receives the decoded Response object
   test: function (Response $Response) {
      // @ Validate status code
      yield assert(
         assertion: $Response->code === 200,
         description: "Status code is 200: {$Response->code}"
      );

      // @ Validate chunked body reassembled correctly over TLS
      yield assert(
         assertion: $Response->Body->raw === "Hello, TLS!\n",
         description: "Chunked body reassembled: " . json_encode($Response->Body->raw)
      );

      // @ Validate decoded body length
      yield assert(
         assertion: $Response->Body->length === 12,
         description: "Body length is 12: {$Response->Body->length}"
      );
   }
);
