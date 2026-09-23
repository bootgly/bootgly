<?php

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test;


// ? H-HCLI-4 — a same-host redirect on a kept-alive connection is a new response leg:
//   its interims are counted from zero, like the legs of a redirect that reconnects.
$interims = static function (int $count): string {
   return str_repeat("HTTP/1.1 103 Early Hints\r\n\r\n", $count);
};

return new Test(
   description: 'It should count interim responses per leg of a kept-alive redirect',

   response: function () { return ''; },
   request: function () { return new Response; },

   keepAlive: true,
   responses: [
      function () use ($interims): string {
         return $interims(40) . "HTTP/1.1 307 Temporary Redirect\r\nLocation: /interim/keepalive/leg2\r\nContent-Length: 0\r\n\r\n";
      },
      function () use ($interims): string {
         return $interims(40) . "HTTP/1.1 200 OK\r\nContent-Length: 4\r\nConnection: close\r\n\r\nleg2";
      },
   ],

   requests: [
      function (HTTP_Client_CLI $Client): Response {
         return $Client->request(method: 'GET', URI: '/interim/keepalive/leg1');
      },
      function (): Response {
         $Response = new Response;
         $Response->code = -1;

         return $Response;
      },
   ],

   test: function (Response $Redirected) {
      yield assert(
         assertion: $Redirected->code === 200 && $Redirected->Body->raw === 'leg2',
         description: "interims are counted per kept-alive redirect leg: {$Redirected->code} {$Redirected->status} {$Redirected->Body->raw}"
      );
   }
);
