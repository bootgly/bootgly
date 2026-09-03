<?php

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test;

$dialsBefore = null;
$dialsAfter = null;

return new Test(
   description: 'It should resolve a relative Location against its own origin, not another client\'s',

   response: function () { return ''; },
   request: function () { return new Response; },

   keepAlive: true,
   responses: [
      // @ A relative Location: the base origin comes from the client itself.
      function () {
         return "HTTP/1.1 302 Found\r\nLocation: /r/final\r\n"
            . "Content-Length: 0\r\n\r\n";
      },
      // @ The redirect leg — report the authority it was sent with.
      function (string $input) {
         $host = '(none)';
         if (preg_match('/^Host:\s*(.+)\r$/mi', $input, $matches) === 1) {
            $host = $matches[1];
         }

         return "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\n"
            . "X-Seen-Host: {$host}\r\n"
            . "Content-Length: 7\r\nConnection: close\r\n\r\nARRIVED";
      },
   ],

   requests: [
      function (HTTP_Client_CLI $Client) use (&$dialsBefore, &$dialsAfter): Response {
         $dialsBefore = $Client->Connections->connections;

         // ! Configured elsewhere, never dialed (HCLI-2)
         $Other = new HTTP_Client_CLI(HTTP_Client_CLI::MODE_TEST);
         $Other->configure(new HTTP_Client_CLI\Configs(host: '127.0.0.1', port: 19809));

         $Response = $Client->request(method: 'GET', URI: '/r/start');

         $dialsAfter = $Client->Connections->connections;

         return $Response;
      },
      function (HTTP_Client_CLI $Client): Response {
         $Response = new Response;
         $Response->code = -1;

         return $Response;
      },
   ],

   test: function (Response $Response1, Response $Response2) use (&$dialsBefore, &$dialsAfter) {
      yield assert(
         assertion: $Response1->code === 200 && $Response1->Body->raw === 'ARRIVED',
         description: "Relative redirect followed: {$Response1->code} "
            . var_export($Response1->Body->raw, true)
      );

      yield assert(
         assertion: $Response1->Header->get('X-Seen-Host') === '127.0.0.1:9999',
         description: 'Redirect leg resolved against its own origin: '
            . var_export($Response1->Header->get('X-Seen-Host'), true)
      );

      $dials = $dialsAfter - $dialsBefore;
      yield assert(
         assertion: $dials === 1,
         description: "Same-origin redirect stayed on the pooled connection: {$dials}"
      );
   }
);
