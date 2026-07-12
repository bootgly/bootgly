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

      // @ Byte-exact delivery under backpressure — the peer STALLS its reads
      //   long enough for the kernel buffers to fill (forcing short/zero
      //   client writes), then drains everything and reports EXACTLY how many
      //   body bytes arrived. A truncating writer cannot pass this: the count
      //   must equal the full payload, not merely "the response timed out".
      $counter = tempnam(sys_get_temp_dir(), 'bootgly-acme-write-count-');
      $payload = 4 * 1024 * 1024;

      $Listener = stream_socket_server(
         'tcp://127.0.0.1:0',
         $code,
         $message,
         STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
      );
      $address = $Listener !== false ? stream_socket_get_name($Listener, false) : false;
      $separator = is_string($address) ? strrpos($address, ':') : false;
      $port = $separator === false ? 0 : (int) substr($address, $separator + 1);

      $Response = null;
      $received = -1;
      $PID = $Listener !== false && $port > 0 ? pcntl_fork() : -1;
      if ($PID === 0) {
         $Peer = @stream_socket_accept($Listener, 5.0);
         if ($Peer !== false) {
            // ! Let the client hit a full kernel buffer before draining
            usleep(300000);

            $head = '';
            while (! str_contains($head, "\r\n\r\n")) {
               $chunk = @fread($Peer, 8192);
               if ($chunk === false || $chunk === '') {
                  usleep(1000);
                  continue;
               }
               $head .= $chunk;
            }
            preg_match('/Content-Length: (\d+)/i', $head, $matches);
            $expected = (int) ($matches[1] ?? 0);
            $body = strlen(substr($head, (int) strpos($head, "\r\n\r\n") + 4));
            $deadline = microtime(true) + 5.0;
            while ($body < $expected && microtime(true) < $deadline) {
               $chunk = @fread($Peer, 65536);
               if ($chunk === false) {
                  break;
               }
               if ($chunk === '') {
                  if (feof($Peer)) {
                     break;
                  }
                  usleep(1000);
                  continue;
               }
               $body += strlen($chunk);
            }
            file_put_contents((string) $counter, (string) $body);
            @fwrite($Peer, "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nok");
            usleep(100000);
            fclose($Peer);
         }
         fclose($Listener);
         exit(0);
      }
      if ($Listener !== false) {
         fclose($Listener);
      }
      if ($PID > 0) {
         $Client = new HTTP_Client_CLI(HTTP_Client_CLI::MODE_TEST);
         $Client->configure('127.0.0.1', $port);
         $Client->connectTimeout = 5;
         $Client->timeout = 5;
         $Client->deadline = microtime(true) + 8.0;

         $Response = $Client->request('POST', '/', body: str_repeat('x', $payload));

         pcntl_waitpid($PID, $status);
         $received = (int) file_get_contents((string) $counter);
      }
      @unlink((string) $counter);

      yield assert(
         assertion: $Response !== null && $Response->code === 200,
         description: 'a backpressured request still completes once the peer drains'
      );
      yield assert(
         assertion: $received === $payload,
         description: "the peer observes the request body byte-exact under forced short writes ({$received} of {$payload})"
      );
   }
);
