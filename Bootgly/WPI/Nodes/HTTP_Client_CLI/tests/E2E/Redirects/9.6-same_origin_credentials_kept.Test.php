<?php

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test;

return new Test(
   description: 'It should keep Authorization and Cookie when a redirect stays on the origin',

   response: function () { return ''; },
   request: function () { return new Response; },

   responses: [
      // @ Same origin, but `Connection: close` routes the leg through follow()
      //   just like a cross-origin hop — the credentials must survive it.
      function () {
         return "HTTP/1.1 307 Temporary Redirect\r\nLocation: /moved\r\n"
            . "Content-Length: 0\r\nConnection: close\r\n\r\n";
      },
      function (string $input) {
         $authorization = stripos($input, "\r\nAuthorization:") !== false ? 'present' : 'absent';
         $cookie = stripos($input, "\r\nCookie:") !== false ? 'present' : 'absent';

         return "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\n"
            . "X-Authorization: {$authorization}\r\nX-Cookie: {$cookie}\r\n"
            . "Content-Length: 4\r\nConnection: close\r\n\r\nDONE";
      },
   ],

   requests: [
      function (HTTP_Client_CLI $Client): Response {
         return $Client->request(
            method: 'POST',
            URI: '/pay',
            headers: [
               'Authorization' => 'Bearer SECRET-TOKEN-abc123',
               'Cookie'        => 'session=deadbeef',
            ],
            body: 'card=4111111111111111'
         );
      },
      function (HTTP_Client_CLI $Client): Response {
         $Response = new Response;
         $Response->code = -1;

         return $Response;
      },
   ],

   test: function (Response $Response1, Response $Response2) {
      yield assert(
         assertion: $Response1->code === 200,
         description: "Same-origin redirect completed: {$Response1->code}"
      );

      yield assert(
         assertion: $Response1->Header->get('X-Authorization') === 'present',
         description: "Authorization kept on the same origin: "
            . var_export($Response1->Header->get('X-Authorization'), true)
      );

      yield assert(
         assertion: $Response1->Header->get('X-Cookie') === 'present',
         description: "Cookie kept on the same origin: "
            . var_export($Response1->Header->get('X-Cookie'), true)
      );
   }
);
