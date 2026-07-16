<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;


/**
 * H3 regression — pending handshakes have a separate two-peer test ceiling.
 */

return new Specification(
   description: 'Pending TLS handshakes must be bounded before crypto',

   test: function (): bool|string {
      $SilentA = null;
      $SilentB = null;
      $Rejected = null;
      $Recovery = null;

      $Open = static function (): mixed {
         return @stream_socket_client(
            'tcp://127.0.0.1:8086',
            $errorNumber,
            $errorMessage,
            2
         );
      };

      $TLSContext = stream_context_create([
         'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
            'peer_name' => 'localhost',
            'alpn_protocols' => 'http/1.1',
         ],
      ]);

      try {
         $SilentA = $Open();
         if (! is_resource($SilentA)) {
            return 'First pending TLS peer could not connect.';
         }
         stream_set_blocking($SilentA, false);
         usleep(100_000);

         $SilentB = $Open();
         if (! is_resource($SilentB)) {
            return 'Second pending TLS peer could not connect.';
         }
         stream_set_blocking($SilentB, false);
         usleep(100_000);

         // @ The worker test ceiling is two. A third TLS peer must be shed
         //   before stream_socket_enable_crypto() is ever attempted server-side.
         $Rejected = @stream_socket_client(
            'ssl://127.0.0.1:8086',
            $errorNumber,
            $errorMessage,
            1,
            STREAM_CLIENT_CONNECT,
            $TLSContext
         );
         if (is_resource($Rejected)) {
            return 'A third pending TLS handshake bypassed the configured ceiling.';
         }

         // @ Releasing one pending peer must return its slot exactly once.
         @fclose($SilentA);
         $SilentA = null;
         usleep(150_000);

         $Recovery = @stream_socket_client(
            'ssl://127.0.0.1:8086',
            $errorNumber,
            $errorMessage,
            2,
            STREAM_CLIENT_CONNECT,
            $TLSContext
         );
         if (! is_resource($Recovery)) {
            return "Pending-handshake slot was not released: {$errorNumber} {$errorMessage}";
         }
         stream_set_blocking($Recovery, false);
         @fwrite(
            $Recovery,
            "GET /h3-ceiling-recovery HTTP/1.1\r\n"
               . "Host: localhost:8086\r\n"
               . "Connection: close\r\n\r\n"
         );
         $response = '';
         $responseDeadlineNS = (int) hrtime(true) + 1_000_000_000;
         while ((int) hrtime(true) < $responseDeadlineNS) {
            $chunk = @fread($Recovery, 65536);
            if ($chunk !== false && $chunk !== '') {
               $response .= $chunk;
               if (str_contains($response, 'uri=/h3-ceiling-recovery')) {
                  break;
               }
            }
            usleep(5_000);
         }

         return str_contains($response, 'uri=/h3-ceiling-recovery')
            ? true
            : 'Recovered pending-handshake slot did not route HTTPS: '
               . json_encode($response);
      }
      finally {
         foreach ([$SilentA, $SilentB, $Rejected, $Recovery] as $Socket) {
            if (is_resource($Socket)) {
               @fclose($Socket);
            }
         }
      }
   }
);
