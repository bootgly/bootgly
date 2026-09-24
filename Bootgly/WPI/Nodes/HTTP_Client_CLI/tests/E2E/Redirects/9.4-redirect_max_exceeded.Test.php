<?php

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test;

return new Test(
   description: 'It should fail loudly when a redirect chain goes past maxRedirects',

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
      // @ Past maxRedirects the request fails with its own status — a 3xx
      //   returned as if it were the answer hides the cut-off chain (M3)
      yield assert(
         assertion: $Response1->code === 0 && $Response1->status === 'Too Many Redirects',
         description: "Fails with 'Too Many Redirects' past the cap: {$Response1->code} "
            . var_export($Response1->status, true)
      );

      yield assert(
         assertion: $Response1->Header->get('Location') === '/redir-3',
         description: "The refused hop's head stays readable — Location: " . ($Response1->Header->get('Location') ?? 'null')
      );
   }
);
