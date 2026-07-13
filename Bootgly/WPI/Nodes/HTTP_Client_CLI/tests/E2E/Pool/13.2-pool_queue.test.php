<?php

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test\Specification;

$dialsBefore = null;
$dialsAfter = null;
$Second = null;
$Third = null;

return new Specification(
   description: 'It should queue batched requests beyond the pool max and promote them as capacity frees',

   response: function () { return ''; },
   request: function () { return new Response; },

   keepAlive: true,
   responses: [
      function () {
         return "HTTP/1.1 200 OK\r\nContent-Length: 1\r\n\r\nA";
      },
      function () {
         return "HTTP/1.1 200 OK\r\nContent-Length: 1\r\n\r\nB";
      },
      function () {
         return "HTTP/1.1 200 OK\r\nContent-Length: 1\r\n\r\nC";
      },
   ],

   requests: [
      function (HTTP_Client_CLI $Client) use (&$dialsBefore, &$dialsAfter, &$Second, &$Third): Response {
         $dialsBefore = $Client->Connections->connections;

         // ! Pool max = 1 (default): requests beyond the first must queue and
         //   promote over the SAME keep-alive connection as responses complete.
         $Client->batch();
         $First = $Client->request(method: 'GET', URI: '/queue/a');
         $Second = $Client->request(method: 'GET', URI: '/queue/b');
         $Third = $Client->request(method: 'GET', URI: '/queue/c');
         $Client->drain();

         $dialsAfter = $Client->Connections->connections;

         return $First;
      },
      function (HTTP_Client_CLI $Client) use (&$Second): Response {
         return $Second;
      },
      function (HTTP_Client_CLI $Client) use (&$Third): Response {
         return $Third;
      },
   ],

   test: function (Response $Response1, Response $Response2, Response $Response3) use (&$dialsBefore, &$dialsAfter) {
      yield assert(
         assertion: $Response1->code === 200 && $Response1->Body->raw === 'A',
         description: "First batched response: {$Response1->code} '{$Response1->Body->raw}'"
      );

      yield assert(
         assertion: $Response2->code === 200 && $Response2->Body->raw === 'B',
         description: "Second (queued) response promoted: {$Response2->code} '{$Response2->Body->raw}'"
      );

      yield assert(
         assertion: $Response3->code === 200 && $Response3->Body->raw === 'C',
         description: "Third (queued) response promoted: {$Response3->code} '{$Response3->Body->raw}'"
      );

      $dials = $dialsAfter - $dialsBefore;
      yield assert(
         assertion: $dials === 1,
         description: "All three batched requests shared ONE dial (max=1 + queue promotion): {$dials}"
      );
   }
);
