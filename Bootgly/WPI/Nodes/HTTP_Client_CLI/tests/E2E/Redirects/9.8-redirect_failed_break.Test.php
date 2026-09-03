<?php

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test;

$portAfter = null;

return new Test(
   description: 'It should conclude as Redirect Failed and restore the origin when a leg cannot be dialed',

   response: function () { return ''; },
   request: function () { return new Response; },

   responses: [
      // @ Hop 1 — point at a port nothing is listening on.
      function () {
         return "HTTP/1.1 302 Found\r\nLocation: http://127.0.0.1:19806/nowhere\r\n"
            . "Content-Length: 0\r\nConnection: close\r\n\r\n";
      },
      // @ A later, unrelated request — proves the client came home.
      function (string $input) {
         $host = '(none)';
         if (preg_match('/^Host:\s*(.+)\r$/mi', $input, $matches) === 1) {
            $host = $matches[1];
         }

         return "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\n"
            . "X-Seen-Host: {$host}\r\n"
            . "Content-Length: 5\r\nConnection: close\r\n\r\nAFTER";
      },
   ],

   requests: [
      function (HTTP_Client_CLI $Client) use (&$portAfter): Response {
         $Client->connectTimeout = 2;

         $Response = $Client->request(method: 'GET', URI: '/start');

         $portAfter = $Client->port;

         // ? Keep the mock cursor aligned while follow() still hijacks the client
         if ($Client->port !== 9999) {
            $Client->configure(new HTTP_Client_CLI\Configs(host: '127.0.0.1', port: 9999));
         }

         $Client->connectTimeout = 30; // @ Restore default

         return $Response;
      },
      function (HTTP_Client_CLI $Client): Response {
         return $Client->request(method: 'GET', URI: '/after');
      },
   ],

   test: function (Response $Response1, Response $Response2) use (&$portAfter) {
      yield assert(
         assertion: $Response1->code === 0,
         description: "Undialable redirect leg fails: {$Response1->code}"
      );

      yield assert(
         assertion: $Response1->status === 'Redirect Failed',
         description: 'Distinct status so a retry cannot re-send the target URI home: '
            . var_export($Response1->status, true)
      );

      yield assert(
         assertion: $portAfter === 9999,
         description: 'Client origin restored after the failed leg: ' . var_export($portAfter, true)
      );

      yield assert(
         assertion: $Response2->Header->get('X-Seen-Host') === '127.0.0.1:9999',
         description: 'The next request went home: '
            . var_export($Response2->Header->get('X-Seen-Host'), true)
      );
   }
);
