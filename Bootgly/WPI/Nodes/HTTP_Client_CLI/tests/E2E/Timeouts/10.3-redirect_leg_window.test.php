<?php

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test\Specification;

$Followed = null;

return new Specification(
   description: 'It should give a redirect leg its own response window',

   response: function () { return ''; },
   request: function () { return new Response; },

   responses: [
      // ! Warm-up under the default timeout — absorbs any lag a preceding spec
      //   left in the single-threaded mock before the tight windows start
      function () {
         return "HTTP/1.1 200 OK\r\nContent-Length: 4\r\nConnection: close\r\n\r\nWARM";
      },
      // @ Leg 1 — a slow redirect. Connection: close routes the next leg
      //   through follow(), which re-dials and re-arms.
      function () {
         return (function () {
            sleep(1);
            yield "HTTP/1.1 302 Found\r\nLocation: /slow/final\r\n"
               . "Content-Length: 0\r\nConnection: close\r\n\r\n";
         })();
      },
      // @ Leg 2 — answers inside ITS OWN window, but outside one measured from
      //   the first dispatch
      function () {
         return (function () {
            sleep(1);
            yield "HTTP/1.1 200 OK\r\nContent-Length: 7\r\nConnection: close\r\n\r\nARRIVED";
         })();
      },
   ],

   requests: [
      function (HTTP_Client_CLI $Client) use (&$Followed): Response {
         $Warm = $Client->request(method: 'GET', URI: '/slow/warm');

         $Client->timeout = 1.5;
         $Followed = $Client->request(method: 'GET', URI: '/slow/start');
         $Client->timeout = 30; // @ Restore default

         return $Warm;
      },
      function (HTTP_Client_CLI $Client) use (&$Followed): Response {
         return $Followed;
      },
      function (HTTP_Client_CLI $Client): Response {
         $Response = new Response;
         $Response->code = -1;

         return $Response;
      },
   ],

   test: function (Response $Warm, Response $Response, Response $Dummy) {
      yield assert(
         assertion: $Warm->code === 200,
         description: "Warm-up completed: {$Warm->code}"
      );

      yield assert(
         assertion: $Response->code === 200 && $Response->Body->raw === 'ARRIVED',
         description: "Redirect leg answered inside its own window: {$Response->code} "
            . var_export($Response->status, true)
      );
   }
);
