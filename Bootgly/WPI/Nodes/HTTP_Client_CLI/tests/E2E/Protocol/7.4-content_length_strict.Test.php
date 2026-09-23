<?php

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test;


// ? M2 — a Content-Length that is not one exact number fails the response ('Invalid Response')
//   instead of being cast, truncated or picked by header position; identical repeats stay valid.
return new Test(
   description: 'It should refuse a malformed or conflicting Content-Length',

   response: function () { return ''; },
   request: function () { return new Response; },

   responses: [
      function (): string {
         return "HTTP/1.1 200 OK\r\nContent-Length: -1\r\nConnection: close\r\n\r\nhello";
      },
      function (): string {
         return "HTTP/1.1 200 OK\r\nContent-Length: 5\r\nContent-Length: 99\r\nConnection: close\r\n\r\nhello";
      },
      function (): string {
         return "HTTP/1.1 200 OK\r\nContent-Length : 5\r\nConnection: close\r\n\r\nhello";
      },
      function (): string {
         return "HTTP/1.1 200 OK\r\nContent-Length: 5, 5\r\nConnection: close\r\n\r\nhello";
      },
   ],

   requests: [
      function (HTTP_Client_CLI $Client): Response {
         return $Client->request(method: 'GET', URI: '/framing/cl-negative');
      },
      function (HTTP_Client_CLI $Client): Response {
         return $Client->request(method: 'GET', URI: '/framing/cl-conflict');
      },
      function (HTTP_Client_CLI $Client): Response {
         return $Client->request(method: 'GET', URI: '/framing/cl-space');
      },
      function (HTTP_Client_CLI $Client): Response {
         return $Client->request(method: 'GET', URI: '/framing/cl-list');
      },
   ],

   test: function (Response $Negative, Response $Conflict, Response $Space, Response $List) {
      yield assert(
         assertion: $Negative->code === 0 && $Negative->status === 'Invalid Response',
         description: "Content-Length: -1 is refused: {$Negative->code} {$Negative->status}"
      );
      yield assert(
         assertion: $Conflict->code === 0 && $Conflict->status === 'Invalid Response',
         description: "Content-Length 5 then 99 is refused: {$Conflict->code} {$Conflict->status}"
      );
      yield assert(
         assertion: $Space->code === 0 && $Space->status === 'Invalid Response',
         description: "whitespace before the colon is refused: {$Space->code} {$Space->status}"
      );
      yield assert(
         assertion: $List->code === 200 && $List->Body->raw === 'hello',
         description: "an identical list stays valid: {$List->code} {$List->Body->raw}"
      );
   }
);
