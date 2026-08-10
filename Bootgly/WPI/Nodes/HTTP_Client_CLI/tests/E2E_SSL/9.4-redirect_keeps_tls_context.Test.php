<?php

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test;

return new Test(
   description: 'It should keep the caller TLS options on a redirect leg',

   response: function () { return ''; },
   request: function () { return new Response; },

   responses: [
      // @ Same origin, but `Connection: close` forces the re-dial through follow()
      function () {
         return "HTTP/1.1 302 Found\r\nLocation: /moved\r\n"
            . "Content-Length: 0\r\nConnection: close\r\n\r\n";
      },
      // @ Leg 2 — only reachable when the TLS handshake succeeds, which needs
      //   the harness's verify_peer=false / allow_self_signed to survive the
      //   redirect. Rebuilt from scratch (HCLI-8), leg 2 verifies the
      //   self-signed certificate against the system store and never arrives.
      function () {
         return "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\n"
            . "Content-Length: 8\r\nConnection: close\r\n\r\nARRIVED!";
      },
   ],

   requests: [
      function (HTTP_Client_CLI $Client): Response {
         return $Client->request(
            method: 'GET',
            URI: '/start'
         );
      },
      function (HTTP_Client_CLI $Client): Response {
         $Response = new Response;
         $Response->code = -1;

         return $Response;
      },
   ],

   test: function (Response $Response1, Response $Response2) {
      yield assert(
         assertion: $Response1->code === 200,
         description: "Redirect leg completed over TLS: {$Response1->code} (0 = handshake lost the caller options)"
      );

      yield assert(
         assertion: $Response1->Body->raw === 'ARRIVED!',
         description: "Body from the redirected TLS leg: " . var_export($Response1->Body->raw, true)
      );
   }
);
