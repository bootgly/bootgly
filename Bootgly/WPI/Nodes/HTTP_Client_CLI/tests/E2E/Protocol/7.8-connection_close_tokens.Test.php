<?php

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test;


// ? HCLI-16 — `Connection:close` (no space) is a close: a same-host redirect leg must go out on
//   a new connection, never on the socket the origin just closed.
return new Test(
   description: 'It should read Connection as a token list and follow a same-host redirect on a new connection',

   response: function () { return ''; },
   request: function () { return new Response; },

   responses: [
      function (): string {
         return "HTTP/1.1 302 Found\r\nLocation: /redirect/final\r\nConnection:close\r\nContent-Length: 0\r\n\r\n";
      },
      function (): string {
         return "HTTP/1.1 200 OK\r\nContent-Length: 8\r\nConnection: close\r\n\r\nFINAL-OK";
      },
   ],

   requests: [
      function (HTTP_Client_CLI $Client): Response {
         $retries = $Client->maxRetries;
         $Client->maxRetries = 0;
         $Response = $Client->request(method: 'GET', URI: '/redirect/start');
         $Client->maxRetries = $retries;

         return $Response;
      },
      // ! The redirect leg consumed the second response
      function (HTTP_Client_CLI $Client): Response {
         $Response = new Response;
         $Response->code = -1;

         return $Response;
      },
   ],

   test: function (Response $Response) {
      yield assert(
         assertion: $Response->code === 200 && $Response->Body->raw === 'FINAL-OK',
         description: "the redirect leg reached the origin on a new connection: {$Response->code} {$Response->status}"
      );
   }
);
