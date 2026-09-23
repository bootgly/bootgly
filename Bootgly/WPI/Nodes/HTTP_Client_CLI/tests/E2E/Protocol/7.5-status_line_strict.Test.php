<?php

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test;


// ? M2 — the status line is `HTTP/1.x SP 3DIGIT [SP reason]` with a code in 100-599; anything
//   else fails the response instead of surfacing a made-up code (or a code 0 "success").
return new Test(
   description: 'It should refuse a malformed status line and accept a missing reason phrase',

   response: function () { return ''; },
   request: function () { return new Response; },

   responses: [
      function (): string {
         return "HTTP/9.9 999 Weird\r\nContent-Length: 5\r\nConnection: close\r\n\r\nhello";
      },
      function (): string {
         return "SSH-2.0 100 x\r\n\r\nHTTP/1.1 200 OK\r\nContent-Length: 5\r\nConnection: close\r\n\r\nhello";
      },
      function (): string {
         return "HTTP/1.1  200 OK\r\nContent-Length: 5\r\nConnection: close\r\n\r\nhello";
      },
      function (): string {
         return "HTTP/1.1 200\r\nContent-Length: 5\r\nConnection: close\r\n\r\nhello";
      },
      function (): string {
         return "HTTP/1.1 200\r\nContent-Length: 10\r\nConnection: close\r\n\r\nhello";
      },
   ],

   requests: [
      function (HTTP_Client_CLI $Client): Response {
         return $Client->request(method: 'GET', URI: '/framing/status-version');
      },
      function (HTTP_Client_CLI $Client): Response {
         return $Client->request(method: 'GET', URI: '/framing/status-foreign');
      },
      function (HTTP_Client_CLI $Client): Response {
         return $Client->request(method: 'GET', URI: '/framing/status-spacing');
      },
      function (HTTP_Client_CLI $Client): Response {
         return $Client->request(method: 'GET', URI: '/framing/status-no-reason');
      },
      function (HTTP_Client_CLI $Client): Response {
         return $Client->request(method: 'GET', URI: '/framing/status-no-reason-truncated');
      },
   ],

   test: function (Response $Version, Response $Foreign, Response $Spacing, Response $Bare, Response $Cut) {
      yield assert(
         assertion: $Version->code === 0 && $Version->status === 'Invalid Response',
         description: "HTTP/9.9 999 is refused: {$Version->code} {$Version->status}"
      );
      yield assert(
         assertion: $Foreign->code === 0 && $Foreign->status === 'Invalid Response',
         description: "a non-HTTP status line is refused, never read as an interim: {$Foreign->code} {$Foreign->status}"
      );
      yield assert(
         assertion: $Spacing->code === 0 && $Spacing->status === 'Invalid Response',
         description: "a double space is refused: {$Spacing->code} {$Spacing->status}"
      );
      yield assert(
         assertion: $Bare->code === 200 && $Bare->status === '' && $Bare->Body->raw === 'hello',
         description: "a status line without a reason phrase is valid: {$Bare->code}"
      );
      yield assert(
         assertion: $Cut->code === 0 && $Cut->status === 'Truncated Response',
         description: "its body cut short is a truncated response, not a closed connection: {$Cut->status}"
      );
   }
);
