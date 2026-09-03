<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Events;


return new Test(
   description: 'It should not carry one client\'s encoding into another client at another origin',
   test: function () {
      $portA = 19883;
      $portB = 19884;

      // ! Both origins echo the authority and the body they received
      $echo = function (int $port): int {
         $forked = pcntl_fork();
         if ($forked !== 0) {
            return $forked;
         }

         $Server = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
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

            $headEnd = strpos($input, "\r\n\r\n");
            $headEnd = $headEnd === false ? 0 : $headEnd + 4;
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

            $host = '(none)';
            if (preg_match('/^Host:\s*(.+)\r$/mi', $input, $matches) === 1) {
               $host = $matches[1];
            }

            $body = "host=[{$host}] body=[" . substr($input, $headEnd) . ']';
            @fwrite(
               $Peer,
               "HTTP/1.1 200 OK\r\nContent-Length: " . strlen($body) . "\r\n\r\n{$body}"
            );
            usleep(50000);
            @fclose($Peer);
         }

         @fclose($Server);
         exit(0);
      };

      // ! One event-driven exchange, then the client is discarded
      $drive = function (int $port, string $body): string {
         $result = '';

         $Client = new HTTP_Client_CLI(HTTP_Client_CLI::MODE_TEST);
         $Client->configure(new HTTP_Client_CLI\Configs(host: '127.0.0.1', port: $port));
         $Client->timeout = 8;

         $Client->on(
            Events::ResponseReceive,
            function ($Request, $Response) use (&$result, $Client): void {
               $result = $Response->Body->raw;
               $Client->Event->loop = false; // @phpstan-ignore-line
            }
         );

         $Client->request('POST', '/submit', body: $body);
         $Socket = $Client->connect();

         $Client->Event->defer(microtime(true) + 8.0, function () use ($Client): void {
            $Client->Event->loop = false; // @phpstan-ignore-line
         });

         if ($Socket !== false) {
            $Client->Event->loop();
         }

         return $result;
      };

      $pidA = $echo($portA);
      usleep(200000);
      $sawA = $drive($portA, 'secret-A');
      pcntl_waitpid($pidA, $statusA);

      $pidB = $echo($portB);
      usleep(200000);
      $sawB = $drive($portB, 'payload-B');
      pcntl_waitpid($pidB, $statusB);


      yield assert(
         assertion: $sawA === "host=[127.0.0.1:{$portA}] body=[secret-A]",
         description: "First client reached its own origin: {$sawA}"
      );

      yield assert(
         assertion: $sawB === "host=[127.0.0.1:{$portB}] body=[payload-B]",
         description: "Second client sent its own authority and body: {$sawB}"
      );
   }
);
