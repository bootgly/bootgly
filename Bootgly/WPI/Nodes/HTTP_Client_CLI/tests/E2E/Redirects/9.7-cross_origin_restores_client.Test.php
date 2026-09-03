<?php

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test;

$portAfter = null;
$hostAfter = null;

return new Test(
   description: 'It should restore the client origin after a cross-origin redirect chain',

   response: function () { return ''; },
   request: function () { return new Response; },

   responses: [
      // @ Hop 1 — send the client to a foreign origin and close, so the leg is
      //   re-dialed through follow().
      function () {
         return "HTTP/1.1 302 Found\r\nLocation: http://127.0.0.1:19805/final\r\n"
            . "Content-Length: 0\r\nConnection: close\r\n\r\n";
      },
      // @ A later, unrelated request — report the authority it was sent with.
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
      function (HTTP_Client_CLI $Client) use (&$portAfter, &$hostAfter): Response {
         // ! Hop 2 — a real foreign origin that ANSWERS instead of bouncing back,
         //   so the chain ends away from home and only an automatic restore can
         //   bring the client back (HCLI-7).
         $forked = pcntl_fork();
         if ($forked === 0) {
            $Server = @stream_socket_server('tcp://127.0.0.1:19805', $errno, $errstr);
            if ($Server === false) {
               exit(1);
            }

            $Peer = @stream_socket_accept($Server, 10);
            if ($Peer !== false) {
               $input = '';
               while (strpos($input, "\r\n\r\n") === false) {
                  $chunk = @fread($Peer, 65535);
                  if ($chunk === false || $chunk === '') {
                     break;
                  }
                  $input .= $chunk;
               }

               @fwrite(
                  $Peer,
                  "HTTP/1.1 200 OK\r\nContent-Length: 5\r\nConnection: close\r\n\r\nFINAL"
               );
               usleep(50000);
               @fclose($Peer);
            }

            @fclose($Server);
            exit(0);
         }

         usleep(200000);

         $Response = $Client->request(method: 'GET', URI: '/start');

         pcntl_waitpid($forked, $status);

         // ! Captured before any realignment below
         $hostAfter = $Client->host;
         $portAfter = $Client->port;

         // ? Keep the mock cursor aligned while follow() still hijacks the client
         if ($Client->port !== 9999) {
            $Client->configure(new HTTP_Client_CLI\Configs(host: '127.0.0.1', port: 9999));
         }

         return $Response;
      },
      function (HTTP_Client_CLI $Client): Response {
         return $Client->request(method: 'GET', URI: '/after');
      },
   ],

   test: function (Response $Response1, Response $Response2) use (&$portAfter, &$hostAfter) {
      yield assert(
         assertion: $Response1->code === 200 && $Response1->Body->raw === 'FINAL',
         description: "Redirect chain completed on the foreign origin: {$Response1->code} "
            . var_export($Response1->Body->raw, true)
      );

      yield assert(
         assertion: $hostAfter === '127.0.0.1' && $portAfter === 9999,
         description: 'Client origin restored after the chain: '
            . var_export($hostAfter, true) . ':' . var_export($portAfter, true)
      );

      yield assert(
         assertion: $Response2->Header->get('X-Seen-Host') === '127.0.0.1:9999',
         description: 'The next request went home: '
            . var_export($Response2->Header->get('X-Seen-Host'), true)
      );
   }
);
