<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;


return new Specification(
   description: 'It should not retry an undialable redirect leg against the restored origin',
   test: function () {
      $port = 19885;
      $dead = 19886;

      // ! The origin counts every connection it accepts, so a retry that comes
      //   back home after the origin was restored is visible
      $countFile = tempnam(sys_get_temp_dir(), 'hcli74');
      if ($countFile === false) {
         $countFile = '/tmp/hcli74.count';
      }
      file_put_contents($countFile, '0');

      $forked = pcntl_fork();
      if ($forked === 0) {
         $Server = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
         if ($Server === false) {
            exit(1);
         }

         $accepted = 0;
         while (true) {
            $Peer = @stream_socket_accept($Server, 2);
            if ($Peer === false) {
               break;
            }

            $accepted++;
            file_put_contents($countFile, (string) $accepted);

            $input = '';
            while (strpos($input, "\r\n\r\n") === false) {
               $chunk = @fread($Peer, 65535);
               if ($chunk === false || $chunk === '') {
                  break;
               }
               $input .= $chunk;
            }

            // @ The first connection is sent to a port nothing listens on
            $response = $accepted === 1
               ? "HTTP/1.1 302 Found\r\nLocation: http://127.0.0.1:{$dead}/nowhere\r\n"
                  . "Content-Length: 0\r\nConnection: close\r\n\r\n"
               : "HTTP/1.1 200 OK\r\nContent-Length: 5\r\nConnection: close\r\n\r\nAGAIN";

            @fwrite($Peer, $response);
            usleep(50000);
            @fclose($Peer);
         }

         @fclose($Server);
         exit(0);
      }

      usleep(200000);

      $Client = new HTTP_Client_CLI(HTTP_Client_CLI::MODE_TEST);
      $Client->configure('127.0.0.1', $port);
      $Client->timeout = 5;
      $Client->connectTimeout = 2;
      // ! A retry budget is on purpose: the veto, not the budget, must stop it
      $Client->maxRetries = 1;
      $Client->retryDelay = 0.05;
      $Client->retryJitter = 0.0;

      $Response = $Client->request('GET', '/start');

      $hostAfter = $Client->host;
      $portAfter = $Client->port;

      pcntl_waitpid($forked, $status);
      $accepted = (int) file_get_contents($countFile);
      @unlink($countFile);

      yield assert(
         assertion: $Response->code === 0 && $Response->status === 'Redirect Failed',
         description: "Undialable leg concluded deterministically: {$Response->code} "
            . var_export($Response->status, true)
      );

      yield assert(
         assertion: $hostAfter === '127.0.0.1' && $portAfter === $port,
         description: 'Client came back to its own origin: '
            . var_export($hostAfter, true) . ':' . var_export($portAfter, true)
      );

      // @ Without the veto, the restored origin would receive the redirect
      //   target's URI on a second connection
      yield assert(
         assertion: $accepted === 1,
         description: "The origin was dialed once, never retried into: {$accepted}"
      );
   }
);
