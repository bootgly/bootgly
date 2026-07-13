<?php

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test\Specification;

$dialsBefore = null;
$dialsAfter = null;

return new Specification(
   description: 'It should NOT retry a sent non-idempotent request (POST) on network failure',

   response: function () { return ''; },
   request: function () { return new Response; },

   responses: [
      // Single connection: server closes after the POST was sent — a POST that
      // reached the wire must never be network-retried.
      function () { return ''; },
   ],

   requests: [
      function (HTTP_Client_CLI $Client) use (&$dialsBefore, &$dialsAfter): Response {
         $Client->maxRetries = 2;
         $Client->retryDelay = 0.1;
         $Client->retryJitter = 0.0;

         $dialsBefore = $Client->Connections->connections;
         $Response = $Client->request(method: 'POST', URI: '/no-retry', body: 'payload');
         $dialsAfter = $Client->Connections->connections;

         // @ Restore defaults
         $Client->maxRetries = 0;
         $Client->retryDelay = 1.0;
         $Client->retryJitter = 0.25;

         return $Response;
      },
   ],

   test: function (Response $Response1) use (&$dialsBefore, &$dialsAfter) {
      yield assert(
         assertion: $Response1->code === 0,
         description: "The POST failed without a response: {$Response1->code}"
      );

      $dials = $dialsAfter - $dialsBefore;
      yield assert(
         assertion: $dials === 1,
         description: "No retry dial was attempted for the sent POST: {$dials}"
      );
   }
);
