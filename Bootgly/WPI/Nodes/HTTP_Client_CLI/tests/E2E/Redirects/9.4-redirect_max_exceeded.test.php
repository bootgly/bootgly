<?php

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test\Specification;

return new Specification(
   description: 'It should stop redirecting when maxRedirects is exceeded',

   response: function () { return ''; },
   request: function () { return new Response; },

   // @ All responses are redirects — client should stop after maxRedirects
   responses: [
      function () {
         return "HTTP/1.1 302 Found\r\nLocation: /redir-1\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";
      },
      function () {
         return "HTTP/1.1 302 Found\r\nLocation: /redir-2\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";
      },
      function () {
         return "HTTP/1.1 302 Found\r\nLocation: /redir-3\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";
      },
   ],

   requests: [
      function (HTTP_Client_CLI $Client): Response {
         // @ Set maxRedirects to 2 so the 3rd redirect is not followed
         $Client->maxRedirects = 2;
         $response = $Client->request(method: 'GET', URI: '/start');
         $Client->maxRedirects = 10; // @ Restore default
         return $response;
      },
      function (HTTP_Client_CLI $Client): Response {
         $r = new Response;
         $r->code = -1;
         return $r;
      },
      function (HTTP_Client_CLI $Client): Response {
         $r = new Response;
         $r->code = -1;
         return $r;
      },
   ],

   test: function (Response $Response1, Response $Response2, Response $Response3) {
      // @ After exceeding maxRedirects, the last redirect response should be returned
      yield assert(
         assertion: $Response1->code === 302,
         description: "Returns last redirect response (302) when max exceeded: {$Response1->code}"
      );

      yield assert(
         assertion: $Response1->Header->get('Location') === '/redir-3',
         description: "Location header from last redirect: " . ($Response1->Header->get('Location') ?? 'null')
      );
   }
);
