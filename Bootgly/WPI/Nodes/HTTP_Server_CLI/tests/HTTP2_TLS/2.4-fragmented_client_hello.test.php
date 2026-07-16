<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;


/**
 * H3 regression — drive a real OpenSSL client through an in-process byte
 * relay that releases its TLS records five bytes at a time. This forces the
 * server handshake to span many read-readiness events.
 */

return new Specification(
   description: 'A fragmented ClientHello must complete across readiness events',

   test: function (): bool|string {
      $Listener = null;
      $TLSClient = null;
      $Downstream = null;
      $Upstream = null;

      try {
         $Listener = @stream_socket_server(
            'tcp://127.0.0.1:0',
            $errorNumber,
            $errorMessage
         );
         if (! is_resource($Listener)) {
            return "Fragment relay could not listen: {$errorNumber} {$errorMessage}";
         }
         $address = stream_socket_get_name($Listener, false);
         if ($address === false) {
            return 'Fragment relay listener has no address.';
         }

         $TLSContext = stream_context_create([
            'ssl' => [
               'verify_peer' => false,
               'verify_peer_name' => false,
               'allow_self_signed' => true,
               'peer_name' => 'localhost',
               'alpn_protocols' => 'http/1.1',
            ],
         ]);
         $TLSClient = @stream_socket_client(
            "tcp://{$address}",
            $errorNumber,
            $errorMessage,
            2,
            STREAM_CLIENT_CONNECT,
            $TLSContext
         );
         $Downstream = is_resource($TLSClient)
            ? @stream_socket_accept($Listener, 2)
            : false;
         $Upstream = @stream_socket_client(
            'tcp://127.0.0.1:8086',
            $errorNumber,
            $errorMessage,
            2
         );
         if (
            ! is_resource($TLSClient)
            || ! is_resource($Downstream)
            || ! is_resource($Upstream)
         ) {
            return 'Fragment relay could not establish all three TCP legs.';
         }

         stream_set_blocking($TLSClient, false);
         stream_set_blocking($Downstream, false);
         stream_set_blocking($Upstream, false);

         $towardServer = '';
         $towardClient = '';
         $fragments = 0;
         $Pump = static function () use (
            $Downstream,
            $Upstream,
            &$towardServer,
            &$towardClient,
            &$fragments
         ): bool {
            $bytes = @fread($Downstream, 65536);
            if ($bytes === false) {
               return false;
            }
            if ($bytes !== '') {
               $towardServer .= $bytes;
            }

            if ($towardServer !== '') {
               $fragment = substr($towardServer, 0, 5);
               $written = @fwrite($Upstream, $fragment);
               if ($written === false) {
                  return false;
               }
               if ($written > 0) {
                  $towardServer = substr($towardServer, $written);
                  $fragments++;
               }
            }

            $bytes = @fread($Upstream, 65536);
            if ($bytes === false) {
               return false;
            }
            if ($bytes !== '') {
               $towardClient .= $bytes;
            }

            if ($towardClient !== '') {
               $written = @fwrite($Downstream, $towardClient);
               if ($written === false) {
                  return false;
               }
               if ($written > 0) {
                  $towardClient = substr($towardClient, $written);
               }
            }

            return true;
         };

         $negotiated = 0;
         $handshakeDeadlineNS = (int) hrtime(true) + 2_000_000_000;
         while ((int) hrtime(true) < $handshakeDeadlineNS) {
            $negotiated = @stream_socket_enable_crypto(
               $TLSClient,
               true,
               STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
                  | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT
            );
            if ($negotiated === false) {
               return 'Fragmented TLS negotiation was rejected.';
            }
            if (! $Pump()) {
               return 'Fragment relay failed during TLS negotiation.';
            }
            if ($negotiated === true) {
               break;
            }

            usleep(1_000);
         }
         if ($negotiated !== true) {
            return 'Fragmented TLS negotiation did not complete before its client budget.';
         }
         if ($fragments < 10) {
            return "ClientHello was not materially fragmented ({$fragments} writes).";
         }

         @fwrite(
            $TLSClient,
            "GET /h3-fragmented HTTP/1.1\r\n"
               . "Host: localhost:8086\r\n"
               . "Connection: close\r\n\r\n"
         );
         $response = '';
         $responseDeadlineNS = (int) hrtime(true) + 2_000_000_000;
         while ((int) hrtime(true) < $responseDeadlineNS) {
            if (! $Pump()) {
               return 'Fragment relay failed during the HTTPS exchange.';
            }

            $chunk = @fread($TLSClient, 65536);
            if ($chunk !== false && $chunk !== '') {
               $response .= $chunk;
               if (str_contains($response, 'uri=/h3-fragmented')) {
                  break;
               }
            }
            usleep(1_000);
         }

         return str_contains($response, 'uri=/h3-fragmented')
            ? true
            : 'Fragmented handshake completed but HTTPS routing did not: '
               . json_encode($response);
      }
      finally {
         foreach ([$TLSClient, $Downstream, $Upstream, $Listener] as $Socket) {
            if (is_resource($Socket)) {
               @fclose($Socket);
            }
         }
      }
   }
);
