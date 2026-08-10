<?php

use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Tests\Suite\Test;

$portAfter = null;

return new Test(
   description: 'It should retry a redirected request against the leg origin, not the caller\'s',

   response: function () { return ''; },
   request: function () { return new Response; },

   responses: [
      // @ Hop 1 — hand the request to a foreign origin.
      function () {
         return "HTTP/1.1 302 Found\r\nLocation: http://127.0.0.1:19808/target\r\n"
            . "Content-Length: 0\r\nConnection: close\r\n\r\n";
      },
      // @ A later, unrelated request. If the retry below was misdirected, THIS
      //   is the connection it consumed and the path proves it.
      function (string $input) {
         $path = '(none)';
         if (preg_match('/^[A-Z]+ (\S+) HTTP/m', $input, $matches) === 1) {
            $path = $matches[1];
         }

         return "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\n"
            . "X-Seen-Path: {$path}\r\n"
            . "Content-Length: 5\r\nConnection: close\r\n\r\nAFTER";
      },
   ],

   requests: [
      function (HTTP_Client_CLI $Client) use (&$portAfter): Response {
         // ! The foreign origin drops the first connection without answering and
         //   serves the retry. The retry must go back to IT — the Request still
         //   carries `/target`, which means nothing to the caller's origin.
         $forked = pcntl_fork();
         if ($forked === 0) {
            $Server = @stream_socket_server('tcp://127.0.0.1:19808', $errno, $errstr);
            if ($Server === false) {
               exit(1);
            }

            $served = 0;
            while ($served < 2) {
               $Peer = @stream_socket_accept($Server, 10);
               if ($Peer === false) {
                  break;
               }

               $input = '';
               while (strpos($input, "\r\n\r\n") === false) {
                  $chunk = @fread($Peer, 65535);
                  if ($chunk === false || $chunk === '') {
                     break;
                  }
                  $input .= $chunk;
               }

               $served++;
               if ($served === 2) {
                  @fwrite(
                     $Peer,
                     "HTTP/1.1 200 OK\r\nContent-Length: 5\r\nConnection: close\r\n\r\nFROMB"
                  );
                  usleep(50000);
               }

               @fclose($Peer);
            }

            @fclose($Server);
            exit(0);
         }

         usleep(200000);

         $Client->maxRetries = 1;
         $Client->retryDelay = 0.05;
         $Client->retryJitter = 0.0;
         $Client->timeout = 3;

         $Response = $Client->request(method: 'GET', URI: '/start');

         pcntl_waitpid($forked, $status);

         $portAfter = $Client->port;

         $Client->maxRetries = 0; // @ Restore default
         $Client->retryDelay = 1.0;
         $Client->retryJitter = 0.25;
         $Client->timeout = 30;

         // ? Realign if the origin was not restored
         if ($Client->port !== 9999) {
            $Client->configure('127.0.0.1', 9999);
         }

         return $Response;
      },
      function (HTTP_Client_CLI $Client): Response {
         return $Client->request(method: 'GET', URI: '/after');
      },
   ],

   test: function (Response $Response1, Response $Response2) use (&$portAfter) {
      yield assert(
         assertion: $Response1->code === 200 && $Response1->Body->raw === 'FROMB',
         description: 'Retry of a redirected leg was served by the leg origin: '
            . "{$Response1->code} " . var_export($Response1->Body->raw, true)
      );

      yield assert(
         assertion: $Response2->Header->get('X-Seen-Path') === '/after',
         description: 'The caller origin was never asked for the redirect target: '
            . var_export($Response2->Header->get('X-Seen-Path'), true)
      );

      yield assert(
         assertion: $portAfter === 9999,
         description: 'Client origin restored once the retries finished: '
            . var_export($portAfter, true)
      );
   }
);
