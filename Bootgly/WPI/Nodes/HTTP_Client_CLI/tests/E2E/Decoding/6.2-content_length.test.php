<?php

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test\Specification;

return new Specification(
   description: 'It should decode response with Content-Length',

   // HTTP response with explicit Content-Length
   response: function () {
      return "HTTP/1.1 200 OK\r\nContent-Type: text/html\r\nContent-Length: 26\r\nConnection: close\r\n\r\n<h1>Content-Length OK</h1>";
   },

   // Closure that triggers the HTTP client request
   request: function (HTTP_Client_CLI $Client): Response {
      return $Client->request(
         method: 'GET',
         URI: '/content-length'
      );
   },

   // Test closure receives the decoded Response object
   test: function (Response $Response) {
      // @ Validate status code
      yield assert(
         assertion: $Response->code === 200,
         description: "Status code is 200"
      );

      // @ Validate Content-Length header
      yield assert(
         assertion: $Response->Header->get('Content-Length') === '26',
         description: "Content-Length header is 26"
      );

      // @ Validate body matches Content-Length
      yield assert(
         assertion: strlen($Response->Body->raw) === 26,
         description: "Body length matches Content-Length"
      );

      // @ Validate body content
      yield assert(
         assertion: $Response->Body->raw === '<h1>Content-Length OK</h1>',
         description: "Body content matches"
      );
   }
);
