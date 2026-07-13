<?php

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test\Specification;

$dialsBefore = null;
$dialsAfter = null;

return new Specification(
   description: 'It should survive a server-closed pooled connection: liveness check (or stale replay) transparently re-dials',

   response: function () { return ''; },
   request: function () { return new Response; },

   // ! keepAlive is FALSE: the mock closes the connection after each response.
   //   The client parks the connection idle (the response itself did not ask to
   //   close) and the SECOND request faces a stale socket — either the pool's
   //   acquire() liveness check drops it, or the zero-byte stale replay kicks
   //   in. Both paths must converge on a fresh dial and a correct response.
   responses: [
      function () {
         return "HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\nalive";
      },
      function () {
         return "HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\nfresh";
      },
   ],

   requests: [
      function (HTTP_Client_CLI $Client) use (&$dialsBefore): Response {
         $dialsBefore = $Client->Connections->connections;
         return $Client->request(method: 'GET', URI: '/stale/first');
      },
      function (HTTP_Client_CLI $Client) use (&$dialsAfter): Response {
         $Response = $Client->request(method: 'GET', URI: '/stale/second');
         $dialsAfter = $Client->Connections->connections;
         return $Response;
      },
   ],

   test: function (Response $Response1, Response $Response2) use (&$dialsBefore, &$dialsAfter) {
      yield assert(
         assertion: $Response1->code === 200 && $Response1->Body->raw === 'alive',
         description: "First response before the server closed: {$Response1->code} '{$Response1->Body->raw}'"
      );

      yield assert(
         assertion: $Response2->code === 200 && $Response2->Body->raw === 'fresh',
         description: "Second response after transparent re-dial: {$Response2->code} '{$Response2->Body->raw}'"
      );

      $dials = $dialsAfter - $dialsBefore;
      yield assert(
         assertion: $dials === 2,
         description: "The stale connection was replaced by a fresh dial: {$dials} dials"
      );
   }
);
