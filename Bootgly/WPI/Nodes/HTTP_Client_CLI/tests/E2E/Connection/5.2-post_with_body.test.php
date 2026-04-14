<?php

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test\Specification;

return new Specification(
   description: 'It should perform a POST request with body',

   // HTTP response - echoes back the request body
   response: function () {
      return "HTTP/1.1 201 Created\r\nContent-Type: application/json\r\nContent-Length: 27\r\nConnection: close\r\n\r\n{\"status\":\"created\",\"id\":1}";
   },

   // Closure that triggers the HTTP client POST request
   request: function (HTTP_Client_CLI $Client): Response {
      return $Client->request(
         method: 'POST',
         URI: '/users',
         headers: [
            'Content-Type' => 'application/json'
         ],
         body: '{"name":"John"}'
      );
   },

   // Test closure receives the decoded Response object
   test: function (Response $Response) {
      // @ Validate status code
      yield assert(
         assertion: $Response->code === 201,
         description: "Status code is 201: {$Response->code}"
      );

      // @ Validate Content-Type header
      yield assert(
         assertion: $Response->Header->get('Content-Type') === 'application/json',
         description: "Content-Type is JSON"
      );

      // @ Validate JSON body parsing
      $decoded = $Response->Body->decode();
      yield assert(
         assertion: is_array($decoded) && ($decoded['status'] ?? null) === 'created',
         description: "JSON body: status=created"
      );

      yield assert(
         assertion: ($decoded['id'] ?? null) === 1,
         description: "JSON body: id=1"
      );
   }
);
