<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Events;


return new Test(
   description: 'H3 event-driven memo consumers must reject public wire replacement',
   test: function () {
      $Listener = @stream_socket_server(
         'tcp://127.0.0.1:0',
         $errorCode,
         $errorMessage,
         STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
      );
      $Channel = @stream_socket_pair(
         STREAM_PF_UNIX,
         STREAM_SOCK_STREAM,
         STREAM_IPPROTO_IP
      );
      $address = is_resource($Listener)
         ? stream_socket_get_name($Listener, false)
         : false;
      $separator = is_string($address) ? strrpos($address, ':') : false;
      $port = $separator === false ? 0 : (int) substr($address, $separator + 1);

      if (is_resource($Listener) === false || is_array($Channel) === false || $port < 1) {
         is_resource($Listener) && fclose($Listener);
         if (is_array($Channel)) {
            fclose($Channel[0]);
            fclose($Channel[1]);
         }

         yield assert(
            assertion: false,
            description: "H3 memo fixture could not open: {$errorMessage} ({$errorCode})"
         );
         return;
      }

      $PID = pcntl_fork();
      if ($PID < 0) {
         fclose($Listener);
         fclose($Channel[0]);
         fclose($Channel[1]);

         yield assert(assertion: false, description: 'H3 memo fixture could not fork');
         return;
      }

      if ($PID === 0) {
         fclose($Channel[0]);
         $wires = [];
         $Peer = @stream_socket_accept($Listener, 3);

         if ($Peer !== false) {
            stream_set_timeout($Peer, 2);
            for ($request = 0; $request < 2; $request++) {
               $wire = '';
               while (strlen($wire) < 65536) {
                  $chunk = @fread($Peer, 65536 - strlen($wire));
                  if ($chunk === false || $chunk === '') {
                     break 2;
                  }
                  $wire .= $chunk;
                  if (str_contains($wire, "\r\n\r\n")) {
                     stream_set_blocking($Peer, false);
                     $read = [$Peer];
                     $write = null;
                     $except = null;
                     if (@stream_select($read, $write, $except, 0, 20000) === 1) {
                        $suffix = @fread($Peer, 65536 - strlen($wire));
                        if (is_string($suffix) && $suffix !== '') {
                           $wire .= $suffix;
                        }
                     }
                     stream_set_blocking($Peer, true);
                     break;
                  }
               }
               $wires[] = $wire;

               $body = str_contains($wire, "\r\n\r\nDELETE /admin")
                  ? 'injected'
                  : 'canonical';
               $connection = $request === 0 ? 'keep-alive' : 'close';
               @fwrite(
                  $Peer,
                  "HTTP/1.1 200 OK\r\nContent-Length: " . strlen($body)
                     . "\r\nConnection: {$connection}\r\n\r\n{$body}"
               );
            }
            @fclose($Peer);
         }

         @fwrite($Channel[1], (string) json_encode(['wires' => $wires]));
         fclose($Channel[1]);
         fclose($Listener);
         exit(0);
      }

      fclose($Channel[1]);
      fclose($Listener);

      $poison = "GET /memo HTTP/1.1\r\n\r\nDELETE /admin HTTP/1.1\r\n\r\n";
      $responses = [];
      $Client = new class(HTTP_Client_CLI::MODE_TEST) extends HTTP_Client_CLI {
         public function poison (string $wire): void
         {
            $Request = $this->cachedRequest;
            if ($Request === null) {
               return;
            }

            $Request->encoded = $wire;
            $Request->encodedHost = $this->host;
            $Request->encodedPort = $this->port;
         }
      };
      $Client->configure('127.0.0.1', $port);
      $Client->timeout = 3;
      $Client->on(
         Events::ResponseReceive,
         function ($Request, $Response) use (&$responses, $Client, $poison): void {
            $responses[] = $Response->Body->raw;
            if (count($responses) === 1) {
               $Client->request('GET', '/memo');
               $Client->poison($poison);
               return;
            }

            $Client->Event->loop = false; // @phpstan-ignore-line
         }
      );

      $Client->request('GET', '/memo');
      $Client->poison($poison);
      $Socket = $Client->connect();
      $Client->Event->defer(microtime(true) + 4.0, function () use ($Client): void {
         $Client->Event->loop = false; // @phpstan-ignore-line
      });
      if ($Socket !== false) {
         $Client->Event->loop();
      }

      $waited = 0;
      $deadline = microtime(true) + 1.0;
      do {
         $waited = pcntl_waitpid($PID, $status, WNOHANG);
         if ($waited === $PID) {
            break;
         }
         usleep(10000);
      }
      while (microtime(true) < $deadline);

      if ($waited !== $PID) {
         posix_kill($PID, SIGTERM);
         pcntl_waitpid($PID, $status);
      }
      $encoded = stream_get_contents($Channel[0]);
      fclose($Channel[0]);
      $evidence = is_string($encoded) ? json_decode($encoded, true) : null;
      $wires = is_array($evidence) && is_array($evidence['wires'] ?? null)
         ? $evidence['wires']
         : [];

      $canonical = count($wires) === 2;
      foreach ($wires as $wire) {
         $canonical = $canonical
            && str_starts_with((string) $wire, "GET /memo HTTP/1.1\r\n")
            && str_contains((string) $wire, "\r\n\r\nDELETE /admin") === false;
      }

      yield assert(
         assertion: $responses === ['canonical', 'canonical'] && $canonical,
         description: 'Both memo consumers rebuild after public wire replacement: '
            . json_encode([
               'responses' => $responses,
               'wires' => array_map('base64_encode', $wires),
            ])
      );
   }
);
