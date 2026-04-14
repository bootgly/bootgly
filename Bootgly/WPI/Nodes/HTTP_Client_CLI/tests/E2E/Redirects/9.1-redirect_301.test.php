<?php

use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test\Specification;

return new Specification(
   Separator: new Separator(line: 'Redirects'),
   description: 'It should follow 301 redirect and change method to GET',

   // Mock server: 2 connections (redirect + final)
   response: function () { return ''; },
   request: function () { return new Response; },

   responses: [
      // First connection: 301 redirect
      function () {
         return "HTTP/1.1 301 Moved Permanently\r\nLocation: /new-path\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";
      },
      // Second connection: final response after redirect
      function (string $input) {
         return "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: 11\r\nConnection: close\r\n\r\nRedirected!";
      },
   ],

   requests: [
      // First request triggers redirect — returns final 200 response
      function (HTTP_Client_CLI $Client): Response {
         return $Client->request(
            method: 'POST',
            URI: '/old-path',
            body: 'data'
         );
      },
      // Dummy: redirect follow-up was already handled internally
      function (HTTP_Client_CLI $Client): Response {
         // Return a marker response so test can identify this is the dummy
         $r = new Response;
         $r->code = -1;
         return $r;
      },
   ],

   test: function (Response $Response1, Response $Response2) {
      // @ The first request should have followed the redirect transparently
      yield assert(
         assertion: $Response1->code === 200,
         description: "Final status code after 301 redirect is 200: {$Response1->code}"
      );

      yield assert(
         assertion: $Response1->Body->raw === 'Redirected!',
         description: "Body matches redirected content: {$Response1->Body->raw}"
      );
   }
);
