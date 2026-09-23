<?php

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test;


// ? M2 — a declared Content-Length past maxResponseBytes fails as soon as the head is parsed,
//   before its body is downloaded (the origin here never even sends it).
return new Test(
   description: 'It should fail a declared Content-Length past maxResponseBytes before its body',

   response: function () { return ''; },
   request: function () { return new Response; },

   responses: [
      function (): string {
         return "HTTP/1.1 200 OK\r\nContent-Length: 100000\r\nConnection: close\r\n\r\nonly-a-prefix";
      },
      function (): string {
         // ! Near the cap: the body alone fits, head + body does not
         return "HTTP/1.1 200 OK\r\nX-Pad: " . str_repeat('h', 1000) . "\r\nContent-Length: 65436\r\nConnection: close\r\n\r\nprefix";
      },
      function (): string {
         return "HTTP/1.1 200 OK\r\nContent-Length: 5\r\nConnection: close\r\n\r\nafter";
      },
   ],

   requests: [
      function (HTTP_Client_CLI $Client): Response {
         $Client->maxResponseBytes = 65536;
         $Client->timeout = 5; // ? Bound any unexpected death mode

         return $Client->request(method: 'GET', URI: '/overflow/declared-cl');
      },
      function (HTTP_Client_CLI $Client): Response {
         return $Client->request(method: 'GET', URI: '/overflow/declared-cl-near');
      },
      function (HTTP_Client_CLI $Client): Response {
         // @ Restore defaults
         $Client->maxResponseBytes = (int) (new ReflectionProperty(HTTP_Client_CLI::class, 'maxResponseBytes'))->getDefaultValue();
         $Response = $Client->request(method: 'GET', URI: '/overflow/declared-cl-after');
         $Client->timeout = 30;

         return $Response;
      },
   ],

   test: function (Response $Refused, Response $Near, Response $After) {
      yield assert(
         assertion: $Refused->code === 0 && $Refused->status === 'Response Too Large',
         description: "the declared oversize fails before its body: {$Refused->code} {$Refused->status}"
      );
      yield assert(
         assertion: $Near->code === 0 && $Near->status === 'Response Too Large',
         description: "the head counts too — a body that fits alone fails with its head: {$Near->code} {$Near->status}"
      );
      yield assert(
         assertion: $After->code === 200 && $After->Body->raw === 'after',
         description: 'the client recovers on the next request'
      );
   }
);
