<?php

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test\Specification;


// ? HCLI-10 regression — on a keep-alive connection there is no EOF to
//   surface the failure, so before the fix a split Content-Length body
//   died as code 0 'Timeout' instead of 'Truncated Response'. The split
//   response must complete in the parse loop and the connection must
//   stay usable for the next request.
return new Specification(
   description: 'It should reassemble a split Content-Length body on a keep-alive connection',

   response: function () { return ''; },
   request: function () { return new Response; },

   keepAlive: true,
   responses: [
      function (): Generator {
         yield "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: 10\r\n\r\n";
         yield 'keep-alive';
      },
      function () {
         return "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: 4\r\n\r\ndone";
      },
   ],

   requests: [
      function (HTTP_Client_CLI $Client): Response {
         $Client->timeout = 5; // ? Bound the pre-fix death mode ('Timeout')
         return $Client->request(method: 'GET', URI: '/keepalive/split');
      },
      function (HTTP_Client_CLI $Client): Response {
         $Response = $Client->request(method: 'GET', URI: '/keepalive/after');
         $Client->timeout = 30; // @ Restore default
         return $Response;
      },
   ],

   test: function (Response $Response1, Response $Response2) {
      yield assert(
         assertion: $Response1->code === 200 && $Response1->Body->raw === 'keep-alive',
         description: "split body completes without a close: {$Response1->code} ({$Response1->status}) '{$Response1->Body->raw}'"
      );
      yield assert(
         assertion: $Response2->code === 200 && $Response2->Body->raw === 'done',
         description: "the kept-alive connection serves the next request: {$Response2->code} '{$Response2->Body->raw}'"
      );
   }
);
