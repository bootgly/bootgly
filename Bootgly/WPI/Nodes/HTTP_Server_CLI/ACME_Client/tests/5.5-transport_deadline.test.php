<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;

return new Specification(
   description: 'ACME transport: one absolute deadline bounds TLS, request writes, headers and bodies',
   test: function () {
      $Run = static function (string $phase): array {
         $Listener = stream_socket_server(
            'tcp://127.0.0.1:0',
            $code,
            $message,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
         );
         if ($Listener === false) {
            return [null, INF];
         }
         $address = stream_socket_get_name($Listener, false);
         $separator = is_string($address) ? strrpos($address, ':') : false;
         $port = $separator === false ? 0 : (int) substr($address, $separator + 1);

         $PID = pcntl_fork();
         if ($PID === 0) {
            $Peer = @stream_socket_accept($Listener, 2.0);
            if ($Peer !== false) {
               if (in_array($phase, ['headers', 'body', 'oversize'], true)) {
                  @fread($Peer, 8192);
               }
               if ($phase === 'body') {
                  @fwrite(
                     $Peer,
                     "HTTP/1.1 200 OK\r\nContent-Length: 100\r\nConnection: close\r\n\r\nx"
                  );
               }
               if ($phase === 'oversize') {
                  $output = "HTTP/1.1 200 OK\r\nContent-Length: 131072\r\nConnection: close\r\n\r\n"
                     . str_repeat('x', 131072);
                  $offset = 0;
                  while ($offset < strlen($output)) {
                     $written = @fwrite($Peer, substr($output, $offset));
                     if ($written === false || $written === 0) {
                        break;
                     }
                     $offset += $written;
                  }
               }
               // TLS: withhold ServerHello. Write: never read the request.
               // Headers: read the request but never answer. Body: advertise
               // more bytes than supplied. Every phase remains stalled.
               usleep(2000000);
               fclose($Peer);
            }
            fclose($Listener);
            exit(0);
         }
         fclose($Listener);
         if ($PID < 1 || $port < 1) {
            return [null, INF];
         }

         $Client = new HTTP_Client_CLI(HTTP_Client_CLI::MODE_TEST);
         $secure = $phase === 'tls'
            ? [
               'verify_peer' => false,
               'verify_peer_name' => false,
               'allow_self_signed' => true
            ]
            : null;
         $Client->configure('127.0.0.1', $port, secure: $secure);
         $Client->connectTimeout = 0.25;
         $Client->timeout = 0.25;
         $Client->deadline = microtime(true) + 0.25;
         $Client->maxResponseBytes = $phase === 'oversize' ? 65536 : 0;

         $started = microtime(true);
         $Response = $Client->request(
            $phase === 'write' ? 'POST' : 'GET',
            '/',
            body: $phase === 'write' ? str_repeat('x', 8 * 1024 * 1024) : null
         );
         $elapsed = microtime(true) - $started;

         posix_kill($PID, SIGKILL);
         pcntl_waitpid($PID, $status);

         return [$Response, $elapsed];
      };

      foreach (['tls', 'write', 'headers', 'body', 'oversize'] as $phase) {
         [$Response, $elapsed] = $Run($phase);

         yield assert(
            assertion: $Response !== null
               && $Response->code === 0
               && ($phase !== 'oversize' || $Response->status === 'Response Too Large')
               && $elapsed < 0.8,
            description: "the absolute deadline interrupts a stalled {$phase} phase"
         );
      }
   }
);
