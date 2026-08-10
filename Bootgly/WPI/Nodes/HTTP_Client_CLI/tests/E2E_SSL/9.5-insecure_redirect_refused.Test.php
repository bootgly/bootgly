<?php

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test;

return new Test(
   description: 'It should refuse an https to http redirect instead of replaying credentials in the clear',

   // @ A 307 keeps the method, the headers AND the body, so following this
   //   Location would put the Authorization header and the body on the wire in
   //   cleartext (HCLI-8). The client must fail the request without dialing.
   response: function () {
      return "HTTP/1.1 307 Temporary Redirect\r\nLocation: http://127.0.0.1:9998/moved\r\n"
         . "Content-Length: 0\r\nConnection: close\r\n\r\n";
   },

   request: function (HTTP_Client_CLI $Client): Response {
      return $Client->request(
         method: 'POST',
         URI: '/pay',
         headers: [
            'Authorization' => 'Bearer SECRET-TOKEN-abc123',
            'Cookie'        => 'session=deadbeef',
         ],
         body: 'card=4111111111111111'
      );
   },

   test: function (Response $Response) {
      yield assert(
         assertion: $Response->code === 0,
         description: "Insecure redirect does not complete: {$Response->code}"
      );

      yield assert(
         assertion: $Response->status === 'Insecure Redirect',
         description: "Failure names the refused downgrade: '{$Response->status}'"
      );
   }
);
