<?php

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test;


// ? M2 — an upstream that never ends its header block is refused at the head cap, even with
//   maxResponseBytes = 0 (unbounded): it used to be buffered until the timeout (a worker was
//   killed at 160 MiB through the embedded HTTP resource).
return new Test(
   description: 'It should refuse a header block past the head cap even when unbounded',

   response: function () { return ''; },
   request: function () { return new Response; },

   responses: [
      function (): string {
         return "HTTP/1.1 200 OK\r\n" . str_repeat('X-Pad: ' . str_repeat('a', 1017) . "\r\n", 128);
      },
      function (): string {
         return "HTTP/1.1 200 OK\r\nContent-Length: 5\r\nConnection: close\r\n\r\nafter";
      },
   ],

   requests: [
      function (HTTP_Client_CLI $Client): Response {
         $Client->maxResponseBytes = 0;
         $Client->timeout = 5; // ? Bound any unexpected death mode

         return $Client->request(method: 'GET', URI: '/overflow/endless-head');
      },
      function (HTTP_Client_CLI $Client): Response {
         // @ Restore defaults
         $Client->maxResponseBytes = (int) (new ReflectionProperty(HTTP_Client_CLI::class, 'maxResponseBytes'))->getDefaultValue();
         $Response = $Client->request(method: 'GET', URI: '/overflow/endless-head-after');
         $Client->timeout = 30;

         return $Response;
      },
   ],

   test: function (Response $Refused, Response $After) {
      yield assert(
         assertion: $Refused->code === 0 && $Refused->status === 'Response Header Fields Too Large',
         description: "the endless head is refused: {$Refused->code} {$Refused->status}"
      );
      yield assert(
         assertion: $After->code === 200 && $After->Body->raw === 'after',
         description: 'the client recovers on the next request'
      );
   }
);
