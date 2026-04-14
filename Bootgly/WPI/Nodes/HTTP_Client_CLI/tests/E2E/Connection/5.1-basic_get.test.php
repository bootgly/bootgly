<?php

use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test\Specification;

return new Specification(
   Separator: new Separator(line: 'Connection'),
   description: 'It should perform a basic GET request and decode response',

   // HTTP response that mock server sends
   response: function () {
      return "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: 13\r\nConnection: close\r\n\r\nHello, World!";
   },

   // Closure that triggers the HTTP client request
   request: function (HTTP_Client_CLI $Client): Response {
      return $Client->request(
         method: 'GET',
         URI: '/'
      );
   },

   // Test closure receives the decoded Response object
   test: function (Response $Response) {
      // @ Validate status code
      yield assert(
         assertion: $Response->code === 200,
         description: "Status code is 200: {$Response->code}"
      );

      // @ Validate body
      yield assert(
         assertion: $Response->Body->raw === 'Hello, World!',
         description: "Body content matches: {$Response->Body->raw}"
      );

      // @ Validate Content-Length
      yield assert(
         assertion: $Response->Body->length === 13,
         description: "Content-Length is 13: {$Response->Body->length}"
      );

      // @ Validate header parsing
      yield assert(
         assertion: $Response->Header->get('Content-Type') === 'text/plain',
         description: "Content-Type header: " . $Response->Header->get('Content-Type')
      );
   }
);
