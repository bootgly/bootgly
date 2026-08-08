<?php

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test\Specification;

$First = null;
$Second = null;
$Third = null;

return new Specification(
   description: 'It should give every promoted batch request its own response window',

   response: function () { return ''; },
   request: function () { return new Response; },

   keepAlive: true,
   responses: [
      // ! Warm-up, answered instantly under the default 30 s timeout. The mock
      //   serves one connection at a time, so a preceding spec that gave up on
      //   a slow response can leave it still writing; this leg absorbs that lag
      //   before the tight windows below start measuring.
      function () {
         return "HTTP/1.1 200 OK\r\nContent-Length: 4\r\n\r\nWARM";
      },
      // ! Pool max = 1 serializes the batch, so the legs are served ~1 s apart.
      //   Each answers well inside a 1.5 s window measured from ITS OWN dispatch
      //   — and far outside one measured from the first arm (HCLI-6).
      function () {
         return (function () {
            sleep(1);
            yield "HTTP/1.1 200 OK\r\nContent-Length: 1\r\n\r\nA";
         })();
      },
      function () {
         return (function () {
            sleep(1);
            yield "HTTP/1.1 200 OK\r\nContent-Length: 1\r\n\r\nB";
         })();
      },
      function () {
         return (function () {
            sleep(1);
            yield "HTTP/1.1 200 OK\r\nContent-Length: 1\r\n\r\nC";
         })();
      },
   ],

   requests: [
      function (HTTP_Client_CLI $Client) use (&$First, &$Second, &$Third): Response {
         $Warm = $Client->request(method: 'GET', URI: '/window/warm');

         $Client->timeout = 1.5;

         $Client->batch();
         $First = $Client->request(method: 'GET', URI: '/window/a');
         $Second = $Client->request(method: 'GET', URI: '/window/b');
         $Third = $Client->request(method: 'GET', URI: '/window/c');
         $Client->drain();

         $Client->timeout = 30; // @ Restore default

         return $Warm;
      },
      function (HTTP_Client_CLI $Client) use (&$First): Response {
         return $First;
      },
      function (HTTP_Client_CLI $Client) use (&$Second): Response {
         return $Second;
      },
      function (HTTP_Client_CLI $Client) use (&$Third): Response {
         return $Third;
      },
   ],

   test: function (Response $Warm, Response $Response1, Response $Response2, Response $Response3) {
      yield assert(
         assertion: $Warm->code === 200 && $Warm->Body->raw === 'WARM',
         description: "Warm-up completed on a pooled keep-alive connection: {$Warm->code}"
      );

      yield assert(
         assertion: $Response1->code === 200 && $Response1->Body->raw === 'A',
         description: "First leg answered inside its window: {$Response1->code} "
            . var_export($Response1->status, true)
      );

      yield assert(
         assertion: $Response2->code === 200 && $Response2->Body->raw === 'B',
         description: "Promoted leg got a fresh window: {$Response2->code} "
            . var_export($Response2->status, true)
      );

      yield assert(
         assertion: $Response3->code === 200 && $Response3->Body->raw === 'C',
         description: "Last promoted leg got a fresh window: {$Response3->code} "
            . var_export($Response3->status, true)
      );
   }
);
