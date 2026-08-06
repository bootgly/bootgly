<?php

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test\Specification;

return new Specification(
   description: 'It should follow an https to http redirect when the caller opts in',

   // @ Same wire as 9.5; only the client configuration differs. The leg is
   //   actually dialed here, so it reaches the TLS mock in cleartext and dies
   //   in the handshake — what matters is that it was NOT refused up front.
   response: function () {
      return "HTTP/1.1 307 Temporary Redirect\r\nLocation: http://127.0.0.1:9998/moved\r\n"
         . "Content-Length: 0\r\nConnection: close\r\n\r\n";
   },

   request: function (HTTP_Client_CLI $Client): Response {
      $Client->allowInsecureRedirect = true;

      $Response = $Client->request(
         method: 'GET',
         URI: '/opted-in'
      );

      // @ Restore the client for any later spec: the opt-in is per client, and
      //   follow() re-points the live instance at the redirect target without
      //   ever restoring it (HCLI-7, still open).
      $Client->allowInsecureRedirect = false;
      $Client->configure('127.0.0.1', 9998, secure: [
         'verify_peer'       => false,
         'verify_peer_name'  => false,
         'allow_self_signed' => true,
      ]);

      return $Response;
   },

   test: function (Response $Response) {
      yield assert(
         assertion: $Response->status !== 'Insecure Redirect',
         description: "Opt-in suppresses the refusal: '{$Response->status}'"
      );
   }
);
