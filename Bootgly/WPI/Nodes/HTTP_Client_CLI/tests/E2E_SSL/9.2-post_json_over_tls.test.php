<?php

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test\Specification;

return new Specification(
   description: 'It should POST a JSON body over TLS and receive JSON response',

   // HTTP response that mock server sends
   response: function (string $input) {
      $body = '{"status":"ok","received":true}';
      $length = strlen($body);

      return "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: {$length}\r\nConnection: close\r\n\r\n{$body}";
   },

   // Closure that triggers the HTTP client request
   request: function (HTTP_Client_CLI $Client): Response {
      return $Client->request(
         method: 'POST',
         URI: '/api/data',
         headers: ['Content-Type' => 'application/json'],
         body: '{"key":"value"}'
      );
   },

   // Test closure receives the decoded Response object
   test: function (Response $Response) {
      // @ Validate status code
      yield assert(
         assertion: $Response->code === 200,
         description: "Status code is 200: {$Response->code}"
      );

      // @ Validate JSON body over TLS
      $decoded = json_decode($Response->Body->raw, true);
      yield assert(
         assertion: $decoded['status'] === 'ok',
         description: "JSON status is 'ok'"
      );

      yield assert(
         assertion: $decoded['received'] === true,
         description: "JSON received flag is true"
      );
   }
);
