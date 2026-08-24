<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Events;


return new Test(
   description: 'It should re-encode the same request when the client is pointed at another origin',
   test: function () {
      $portA = 19887;
      $portB = 19888;

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
            stream_set_timeout($Peer, 5);

            $input = '';
            while (strpos($input, "\r\n\r\n") === false) {
               $chunk = @fread($Peer, 65535);
               if ($chunk === false || $chunk === '') {
                  break;
               }
               $input .= $chunk;
            }

            $host = '(none)';
            if (preg_match('/^Host:\s*(.+)\r$/mi', $input, $matches) === 1) {
               $host = $matches[1];
            }

            // ! Connection: close on purpose. Event-driven connections are not
            //   pooled, so configure() cannot retire them; a keep-alive leg
            //   would survive the re-point and answer from its stale state.
            @fwrite(
               $Peer,
               "HTTP/1.1 200 OK\r\nContent-Length: " . strlen($host)
               . "\r\nConnection: close\r\n\r\n{$host}"
            );
            usleep(50000);
            @fclose($Peer);
         }

         @fclose($Server);
         exit(0);
      };

      // ! ONE client, two origins, the same method+URI — so the cached request
      //   object is reused and only its origin changes
      $seen = [];

      $Client = new HTTP_Client_CLI(HTTP_Client_CLI::MODE_TEST);
      $Client->timeout = 8;
      $Client->on(
         Events::ResponseReceive,
         function ($Request, $Response) use (&$seen, $Client): void {
            $seen[] = $Response->Body->raw;
            $Client->Event->loop = false; // @phpstan-ignore-line
         }
      );

      foreach ([$portA, $portB] as $port) {
         $pid = $echo($port);
         usleep(200000);

         $Client->configure('127.0.0.1', $port);
         $Client->request('GET', '/one');
         $Socket = $Client->connect();

         $Client->Event->defer(microtime(true) + 8.0, function () use ($Client): void {
            $Client->Event->loop = false; // @phpstan-ignore-line
         });

         if ($Socket !== false) {
            // ! The reactor is persistent and the previous leg stopped it
            $Client->Event->loop = true; // @phpstan-ignore-line
            $Client->Event->loop();
         }

         pcntl_waitpid($pid, $status);
      }

      new HTTP_Client_CLI(HTTP_Client_CLI::MODE_TEST);

      yield assert(
         assertion: ($seen[0] ?? null) === "127.0.0.1:{$portA}",
         description: 'First origin saw its own authority: ' . var_export($seen[0] ?? null, true)
      );

      yield assert(
         assertion: ($seen[1] ?? null) === "127.0.0.1:{$portB}",
         description: 'Re-pointed client announced the new authority: '
            . var_export($seen[1] ?? null, true)
      );
   }
);
