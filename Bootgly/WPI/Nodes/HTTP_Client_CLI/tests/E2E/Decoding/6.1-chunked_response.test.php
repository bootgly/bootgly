<?php

use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test\Specification;

return new Specification(
   Separator: new Separator(line: 'Decoding'),
   description: 'It should decode chunked transfer-encoding response',

   // HTTP response with chunked transfer-encoding
   response: function () {
      return "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\nContent-Type: text/plain\r\nConnection: close\r\n\r\n7\r\nHello, \r\n6\r\nWorld!\r\n0\r\n\r\n";
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
         description: "Status code is 200"
      );

      // @ Validate body was correctly assembled from chunks
      yield assert(
         assertion: $Response->Body->raw === 'Hello, World!',
         description: "Chunked body assembled: {$Response->Body->raw}"
      );

      // @ Validate body length
      yield assert(
         assertion: $Response->Body->length === 13,
         description: "Body length is 13: {$Response->Body->length}"
      );

      // @ Validate body is complete (not waiting)
      yield assert(
         assertion: $Response->Body->waiting === false,
         description: "Body is complete (not waiting)"
      );
   }
);
