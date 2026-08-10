<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Events;


return new Test(
   description: 'It should send each event-driven body instead of replaying the first one',
   test: function () {
      $port = 19882;
      $bodies = ['alpha', 'bravo', 'charlie'];

      // ! A keep-alive origin that echoes back the body it actually received
      $forked = pcntl_fork();
      if ($forked === 0) {
         $Server = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
         if ($Server === false) {
            exit(1);
         }

         $Peer = @stream_socket_accept($Server, 10);
         if ($Peer !== false) {
            while (true) {
               $input = '';
               while (strpos($input, "\r\n\r\n") === false) {
                  $chunk = @fread($Peer, 65535);
                  if ($chunk === false || $chunk === '') {
                     break 2;
                  }
                  $input .= $chunk;
               }

               $headEnd = strpos($input, "\r\n\r\n") + 4;
               if (preg_match('/Content-Length:\s*(\d+)/i', $input, $matches) === 1) {
                  $need = (int) $matches[1];
                  while (strlen($input) - $headEnd < $need) {
                     $chunk = @fread($Peer, $need - (strlen($input) - $headEnd));
                     if ($chunk === false || $chunk === '') {
                        break;
                     }
                     $input .= $chunk;
                  }
               }

               $body = 'body=[' . substr($input, $headEnd) . ']';
               @fwrite(
                  $Peer,
                  "HTTP/1.1 200 OK\r\nContent-Length: " . strlen($body) . "\r\n\r\n{$body}"
               );
            }
         }

         @fclose($Server);
         exit(0);
      }

      usleep(200000);

      $index = 0;
      $served = [];

      $Client = new HTTP_Client_CLI(HTTP_Client_CLI::MODE_TEST);
      $Client->configure('127.0.0.1', $port);
      $Client->timeout = 8;

      $Client->on(
         Events::ResponseReceive,
         function ($Request, $Response) use (&$index, &$served, $bodies, $Client): void {
            $served[] = $Response->Body->raw;

            if ($index < count($bodies)) {
               $Client->request('POST', '/submit', body: $bodies[$index++]);

               return;
            }

            HTTP_Client_CLI::$Event->loop = false; // @phpstan-ignore-line
         }
      );

      $Client->request('POST', '/submit', body: $bodies[$index++]);
      $Socket = $Client->connect();

      HTTP_Client_CLI::$Event->defer(microtime(true) + 8.0, function (): void {
         HTTP_Client_CLI::$Event->loop = false; // @phpstan-ignore-line
      });

      if ($Socket !== false) {
         HTTP_Client_CLI::$Event->loop();
      }

      posix_kill($forked, SIGTERM);
      pcntl_waitpid($forked, $status);

      new HTTP_Client_CLI(HTTP_Client_CLI::MODE_TEST);

      $expected = [];
      foreach ($bodies as $body) {
         $expected[] = "body=[{$body}]";
      }

      yield assert(
         assertion: $served === $expected,
         description: 'Each POST carried its own body: ' . implode(', ', $served)
      );
   }
);
