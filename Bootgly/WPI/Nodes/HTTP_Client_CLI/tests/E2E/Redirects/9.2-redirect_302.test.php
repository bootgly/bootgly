<?php

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test\Specification;

return new Specification(
   description: 'It should follow 302 redirect and change POST to GET',

   response: function () { return ''; },
   request: function () { return new Response; },

   responses: [
      function () {
         return "HTTP/1.1 302 Found\r\nLocation: /found-here\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";
      },
      function (string $input) {
         // @ Verify the redirected request uses GET (not POST)
         $method = strstr($input, ' ', true);
         return "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: 8\r\nX-Method: {$method}\r\nConnection: close\r\n\r\n302 Done";
      },
   ],

   requests: [
      function (HTTP_Client_CLI $Client): Response {
         return $Client->request(
            method: 'POST',
            URI: '/original',
            body: 'payload'
         );
      },
      function (HTTP_Client_CLI $Client): Response {
         $r = new Response;
         $r->code = -1;
         return $r;
      },
   ],

   test: function (Response $Response1, Response $Response2) {
      yield assert(
         assertion: $Response1->code === 200,
         description: "Final status code after 302 redirect is 200: {$Response1->code}"
      );

      yield assert(
         assertion: $Response1->Body->raw === '302 Done',
         description: "Body matches: {$Response1->Body->raw}"
      );

      // @ Verify method was changed to GET
      yield assert(
         assertion: $Response1->Header->get('X-Method') === 'GET',
         description: "Method changed to GET after 302: " . $Response1->Header->get('X-Method')
      );
   }
);
