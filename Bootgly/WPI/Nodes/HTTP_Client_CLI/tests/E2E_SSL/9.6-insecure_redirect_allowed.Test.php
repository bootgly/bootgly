<?php

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test;

$secureAfter = null;
$portAfter = null;

return new Test(
   description: 'It should follow an https to http redirect when the caller opts in',

   // @ Same wire as 9.5; only the client configuration differs. The leg is
   //   actually dialed here, so it reaches the TLS mock in cleartext and dies
   //   in the handshake — what matters is that it was NOT refused up front.
   response: function () {
      return "HTTP/1.1 307 Temporary Redirect\r\nLocation: http://127.0.0.1:9998/moved\r\n"
         . "Content-Length: 0\r\nConnection: close\r\n\r\n";
   },

   request: function (HTTP_Client_CLI $Client) use (&$secureAfter, &$portAfter): Response {
      $Client->allowInsecureRedirect = true;

      $Response = $Client->request(
         method: 'GET',
         URI: '/opted-in'
      );

      // ! The downgraded hop leaves the client in cleartext unless follow()
      //   restores the transport it was configured with (HCLI-7)
      $secureAfter = $Client->secure !== null;
      $portAfter = $Client->port;

      // ! Only the opt-in is restored by hand: the origin and its TLS options
      //   come back on their own now
      $Client->allowInsecureRedirect = false;

      return $Response;
   },

   test: function (Response $Response) use (&$secureAfter, &$portAfter) {
      yield assert(
         assertion: $Response->status !== 'Insecure Redirect',
         description: "Opt-in suppresses the refusal: '{$Response->status}'"
      );

      yield assert(
         assertion: $secureAfter === true && $portAfter === 9998,
         description: 'TLS transport restored after the downgraded hop: secure='
            . var_export($secureAfter, true) . " port=" . var_export($portAfter, true)
      );
   }
);
