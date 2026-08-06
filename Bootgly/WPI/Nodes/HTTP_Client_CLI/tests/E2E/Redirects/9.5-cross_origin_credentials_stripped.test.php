<?php

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test\Specification;

return new Specification(
   description: 'It should drop Authorization and Cookie when a redirect leaves the origin',

   response: function () { return ''; },
   request: function () { return new Response; },

   responses: [
      // @ Hop 1 — the mock sends the request to a foreign origin. A 307 keeps
      //   the method, the headers and the body, so the credentials issued for
      //   this origin would travel to the next one (HCLI-8).
      function () {
         return "HTTP/1.1 307 Temporary Redirect\r\nLocation: http://127.0.0.1:19804/moved\r\n"
            . "Content-Length: 0\r\nConnection: close\r\n\r\n";
      },
      // @ Hop 3 — back on this origin, report what survived the round trip.
      function (string $input) {
         $authorization = stripos($input, "\r\nAuthorization:") !== false ? 'present' : 'absent';
         $cookie = stripos($input, "\r\nCookie:") !== false ? 'present' : 'absent';

         return "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\n"
            . "X-Authorization: {$authorization}\r\nX-Cookie: {$cookie}\r\n"
            . "Content-Length: 4\r\nConnection: close\r\n\r\nDONE";
      },
   ],

   requests: [
      function (HTTP_Client_CLI $Client): Response {
         // ! Hop 2 — a real foreign origin that bounces the request straight
         //   back here, so the client ends this spec on its original target
         //   and the mock above can inspect the replayed headers.
         $forked = pcntl_fork();
         if ($forked === 0) {
            $Server = @stream_socket_server('tcp://127.0.0.1:19804', $errno, $errstr);
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
                  "HTTP/1.1 307 Temporary Redirect\r\nLocation: http://127.0.0.1:9999/final\r\n"
                  . "Content-Length: 0\r\nConnection: close\r\n\r\n"
               );
               usleep(50000);
               @fclose($Peer);
            }

            @fclose($Server);
            exit(0);
         }

         usleep(200000);

         $Response = $Client->request(
            method: 'POST',
            URI: '/pay',
            headers: [
               // ! Header names are stored verbatim, so a lowercase spelling
               //   only gets dropped by a case-insensitive match
               'Authorization' => 'Bearer SECRET-TOKEN-abc123',
               'cookie'        => 'session=deadbeef',
            ],
            body: 'card=4111111111111111'
         );

         pcntl_waitpid($forked, $status);

         return $Response;
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
         description: "Round trip through the foreign origin completed: {$Response1->code}"
      );

      yield assert(
         assertion: $Response1->Header->get('X-Authorization') === 'absent',
         description: "Authorization dropped on the cross-origin hop: "
            . var_export($Response1->Header->get('X-Authorization'), true)
      );

      yield assert(
         assertion: $Response1->Header->get('X-Cookie') === 'absent',
         description: "Cookie dropped on the cross-origin hop: "
            . var_export($Response1->Header->get('X-Cookie'), true)
      );
   }
);
