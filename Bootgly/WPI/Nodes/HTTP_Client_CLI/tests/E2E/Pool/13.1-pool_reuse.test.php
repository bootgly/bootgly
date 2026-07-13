<?php

use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test\Specification;

$dialsBefore = null;
$dialsAfter = null;

return new Specification(
   Separator: new Separator(line: 'Pool'),
   description: 'It should reuse one pooled keep-alive connection across sequential requests',

   response: function () { return ''; },
   request: function () { return new Response; },

   keepAlive: true,
   responses: [
      function () {
         return "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: 5\r\n\r\nfirst";
      },
      function () {
         return "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: 6\r\n\r\nsecond";
      },
   ],

   requests: [
      function (HTTP_Client_CLI $Client) use (&$dialsBefore): Response {
         $dialsBefore = $Client->Connections->connections;
         return $Client->request(method: 'GET', URI: '/pool/first');
      },
      function (HTTP_Client_CLI $Client) use (&$dialsAfter): Response {
         $Response = $Client->request(method: 'GET', URI: '/pool/second');
         $dialsAfter = $Client->Connections->connections;
         return $Response;
      },
   ],

   test: function (Response $Response1, Response $Response2) use (&$dialsBefore, &$dialsAfter) {
      yield assert(
         assertion: $Response1->code === 200 && $Response1->Body->raw === 'first',
         description: "First response over the pooled connection: {$Response1->code} '{$Response1->Body->raw}'"
      );

      yield assert(
         assertion: $Response2->code === 200 && $Response2->Body->raw === 'second',
         description: "Second response reusing the same connection: {$Response2->code} '{$Response2->Body->raw}'"
      );

      $dials = $dialsAfter - $dialsBefore;
      yield assert(
         assertion: $dials === 1,
         description: "Both requests shared ONE dial (keep-alive pool reuse): {$dials}"
      );
   }
);
