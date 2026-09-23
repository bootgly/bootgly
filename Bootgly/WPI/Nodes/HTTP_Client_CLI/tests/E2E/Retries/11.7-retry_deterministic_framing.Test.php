<?php

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test;


// ? M2 — a response refused for its framing is deterministic: retrying downloads the same
//   refusal again. With maxRetries = 2 the origin is asked once — the next request gets its
//   own response, not the one a retry would have consumed.
return new Test(
   description: 'It should never retry a response refused for its framing or its head size',

   response: function () { return ''; },
   request: function () { return new Response; },

   responses: [
      function (): string {
         return "HTTP/1.1  200 OK\r\nContent-Length: 5\r\nConnection: close\r\n\r\nhello";
      },
      function (): string {
         return "HTTP/1.1 200 OK\r\nContent-Length: 6\r\nConnection: close\r\n\r\nmarker";
      },
      function (): string {
         return "HTTP/1.1 200 OK\r\n" . str_repeat('X-Pad: ' . str_repeat('a', 1017) . "\r\n", 128);
      },
      function (): string {
         return "HTTP/1.1 200 OK\r\nContent-Length: 7\r\nConnection: close\r\n\r\nmarker2";
      },
      function (): string {
         return "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\nZZ\r\nhello\r\n0\r\n\r\n";
      },
      function (): string {
         return "HTTP/1.1 200 OK\r\nContent-Length: 7\r\nConnection: close\r\n\r\nmarker3";
      },
   ],

   requests: [
      function (HTTP_Client_CLI $Client): Response {
         $Client->maxRetries = 2;
         $Client->retryDelay = 0.05;
         $Response = $Client->request(method: 'GET', URI: '/retry/framing');
         $Client->maxRetries = 0; // @ Restore default
         $Client->retryDelay = 1.0; // @ Restore default

         return $Response;
      },
      function (HTTP_Client_CLI $Client): Response {
         return $Client->request(method: 'GET', URI: '/retry/marker');
      },
      function (HTTP_Client_CLI $Client): Response {
         $Client->maxRetries = 2;
         $Client->retryDelay = 0.05;
         $Response = $Client->request(method: 'GET', URI: '/retry/head');
         $Client->maxRetries = 0; // @ Restore default
         $Client->retryDelay = 1.0; // @ Restore default

         return $Response;
      },
      function (HTTP_Client_CLI $Client): Response {
         return $Client->request(method: 'GET', URI: '/retry/marker2');
      },
      function (HTTP_Client_CLI $Client): Response {
         $Client->maxRetries = 2;
         $Client->retryDelay = 0.05;
         $Response = $Client->request(method: 'GET', URI: '/retry/chunk');
         $Client->maxRetries = 0; // @ Restore default
         $Client->retryDelay = 1.0; // @ Restore default

         return $Response;
      },
      function (HTTP_Client_CLI $Client): Response {
         return $Client->request(method: 'GET', URI: '/retry/marker3');
      },
   ],

   test: function (Response $Refused, Response $Marker, Response $Head, Response $Marker2, Response $Chunk, Response $Marker3) {
      yield assert(
         assertion: $Refused->code === 0 && $Refused->status === 'Invalid Response',
         description: "the refused response stays refused: {$Refused->code} {$Refused->status}"
      );
      yield assert(
         assertion: $Marker->code === 200 && $Marker->Body->raw === 'marker',
         description: "no retry consumed the next response: {$Marker->code} {$Marker->Body->raw}"
      );
      yield assert(
         assertion: $Head->code === 0 && $Head->status === 'Response Header Fields Too Large'
            && $Marker2->code === 200 && $Marker2->Body->raw === 'marker2',
         description: "a head past its cap is not retried: {$Head->status} / {$Marker2->Body->raw}"
      );
      yield assert(
         assertion: $Chunk->code === 0 && $Chunk->status === 'Invalid Chunked Encoding'
            && $Marker3->code === 200 && $Marker3->Body->raw === 'marker3',
         description: "invalid chunked framing is not retried: {$Chunk->status} / {$Marker3->Body->raw}"
      );
   }
);
