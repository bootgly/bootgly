<?php

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test\Specification;

return new Specification(
   description: 'It should perform a GET request over TLS and decode the response',

   // HTTP response that mock server sends (after TLS handshake)
   response: function () {
      return "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: 14\r\nConnection: close\r\n\r\nSecure Hello!\n";
   },

   // Closure that triggers the HTTP client request
   request: function (HTTP_Client_CLI $Client): Response {
      return $Client->request(
         method: 'GET',
         URI: '/secure'
      );
   },

   // Test closure receives the decoded Response object
   test: function (Response $Response) {
      // @ Validate status code
      yield assert(
         assertion: $Response->code === 200,
         description: "Status code is 200: {$Response->code}"
      );

      // @ Validate body through TLS
      yield assert(
         assertion: $Response->Body->raw === "Secure Hello!\n",
         description: "Body content matches over TLS"
      );

      // @ Validate Content-Length
      yield assert(
         assertion: $Response->Body->length === 14,
         description: "Content-Length is 14: {$Response->Body->length}"
      );

      // @ Validate header parsing over TLS
      yield assert(
         assertion: $Response->Header->get('Content-Type') === 'text/plain',
         description: "Content-Type header: " . $Response->Header->get('Content-Type')
      );
   }
);
