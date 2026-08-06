<?php

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test\Specification;


// ? HCLI-19 regression (the denial of service) — a chunk-size line at or above
//   2^63 cast to PHP_INT_MIN, a negative size that slipped past every guard and
//   made the decode loop's slicing arithmetic never advance: `decode()` never
//   returned and the process pegged a CPU forever. One crafted line from any
//   origin was enough. Before the fix this spec does not fail — it HANGS the
//   whole run, which is exactly the defect.
return new Specification(
   description: 'It should refuse a chunk-size line at or above 2^63 instead of looping forever',

   response: function () { return ''; },
   request: function () { return new Response; },

   responses: [
      function (): string {
         return "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\nConnection: close\r\n\r\n8000000000000000\r\nxx\r\n";
      },
      function (): string {
         return "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: 5\r\nConnection: close\r\n\r\nafter";
      },
   ],

   requests: [
      function (HTTP_Client_CLI $Client): Response {
         $Client->timeout = 5; // ? No timeout can break a synchronous spin
         return $Client->request(method: 'GET', URI: '/overflow/negative-size');
      },
      function (HTTP_Client_CLI $Client): Response {
         $Response = $Client->request(method: 'GET', URI: '/overflow/negative-after');
         $Client->timeout = 30; // @ Restore default
         return $Response;
      },
   ],

   test: function (Response $Response1, Response $Response2) {
      yield assert(
         assertion: $Response1->code === 0 && $Response1->status === 'Response Too Large',
         description: "the impossible size is refused: code {$Response1->code} ('{$Response1->status}')"
      );
      yield assert(
         assertion: $Response2->code === 200 && $Response2->Body->raw === 'after',
         description: "the client recovers on the next request: {$Response2->code} '{$Response2->Body->raw}'"
      );
   }
);
