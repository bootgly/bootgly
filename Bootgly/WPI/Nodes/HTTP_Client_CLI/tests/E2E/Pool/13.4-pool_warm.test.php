<?php

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test\Specification;

$dialsBefore = null;
$dialsAfter = null;

return new Specification(
   description: 'It should warm the pool minimum lazily and serve requests from the pre-dialed connection',

   response: function () { return ''; },
   request: function () { return new Response; },

   keepAlive: true,
   responses: [
      function () {
         return "HTTP/1.1 200 OK\r\nContent-Length: 4\r\n\r\nwarm";
      },
      function () {
         return "HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\nagain";
      },
   ],

   requests: [
      function (HTTP_Client_CLI $Client) use (&$dialsBefore): Response {
         // ! min=1 pre-dials one connection on the first request; max=2 leaves
         //   headroom that must NOT be used while the warm connection idles.
         $Client->configure('127.0.0.1', 9999, pool: ['min' => 1, 'max' => 2]);

         $dialsBefore = $Client->Connections->connections;

         return $Client->request(method: 'GET', URI: '/warm/first');
      },
      function (HTTP_Client_CLI $Client) use (&$dialsAfter): Response {
         $Response = $Client->request(method: 'GET', URI: '/warm/second');

         $dialsAfter = $Client->Connections->connections;

         // @ Restore the default pool bounds for the next specs
         $Client->configure('127.0.0.1', 9999, pool: ['min' => 0, 'max' => 1]);

         return $Response;
      },
   ],

   test: function (Response $Response1, Response $Response2) use (&$dialsBefore, &$dialsAfter) {
      yield assert(
         assertion: $Response1->code === 200 && $Response1->Body->raw === 'warm',
         description: "First response over the warmed connection: {$Response1->code} '{$Response1->Body->raw}'"
      );

      yield assert(
         assertion: $Response2->code === 200 && $Response2->Body->raw === 'again',
         description: "Second response reusing the warmed connection: {$Response2->code} '{$Response2->Body->raw}'"
      );

      $dials = $dialsAfter - $dialsBefore;
      yield assert(
         assertion: $dials === 1,
         description: "Warm-up + both requests used exactly ONE dial: {$dials}"
      );
   }
);
