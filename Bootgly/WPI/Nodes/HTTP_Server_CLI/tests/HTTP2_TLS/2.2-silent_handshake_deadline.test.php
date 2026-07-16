<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;


/**
 * H3 regression — a peer that never sends ClientHello must be reaped by the
 * absolute handshake deadline without harming the worker.
 */

return new Specification(
   description: 'A silent TLS peer must be closed at the handshake deadline',

   test: function (): bool|string {
      $Silent = null;
      $TLSClient = null;

      try {
         $Silent = @stream_socket_client(
            'tcp://127.0.0.1:8086',
            $errorNumber,
            $errorMessage,
            2
         );
         if (! is_resource($Silent)) {
            return "Silent deadline peer could not connect: {$errorNumber} {$errorMessage}";
         }
         stream_set_blocking($Silent, false);

         $startedNS = (int) hrtime(true);
         $deadlineNS = $startedNS + 1_600_000_000;
         $closed = false;

         while ((int) hrtime(true) < $deadlineNS) {
            @fread($Silent, 1);
            if (feof($Silent)) {
               $closed = true;
               break;
            }

            usleep(5_000);
         }

         $elapsedMS = ((int) hrtime(true) - $startedNS) / 1_000_000;
         if (! $closed) {
            return "Silent TLS peer remained open past its deadline ({$elapsedMS} ms).";
         }
         if ($elapsedMS < 500.0 || $elapsedMS > 1_600.0) {
            return "Handshake deadline fired outside the expected window ({$elapsedMS} ms).";
         }

         // @ Recovery control: deadline cleanup must leave the worker able to
         //   negotiate and route a fresh HTTPS request immediately.
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
            'ssl://127.0.0.1:8086',
            $errorNumber,
            $errorMessage,
            2,
            STREAM_CLIENT_CONNECT,
            $TLSContext
         );
         if (! is_resource($TLSClient)) {
            return "Worker did not recover after handshake timeout: {$errorNumber} {$errorMessage}";
         }
         stream_set_blocking($TLSClient, false);
         @fwrite(
            $TLSClient,
            "GET /h3-deadline-recovery HTTP/1.1\r\n"
               . "Host: localhost:8086\r\n"
               . "Connection: close\r\n\r\n"
         );
         $response = '';
         $responseDeadlineNS = (int) hrtime(true) + 1_000_000_000;
         while ((int) hrtime(true) < $responseDeadlineNS) {
            $chunk = @fread($TLSClient, 65536);
            if ($chunk !== false && $chunk !== '') {
               $response .= $chunk;
               if (str_contains($response, 'uri=/h3-deadline-recovery')) {
                  break;
               }
            }
            usleep(5_000);
         }

         return str_contains($response, 'uri=/h3-deadline-recovery')
            ? true
            : 'Worker negotiated after timeout but did not route the recovery request: '
               . json_encode($response);
      }
      finally {
         foreach ([$Silent, $TLSClient] as $Socket) {
            if (is_resource($Socket)) {
               @fclose($Socket);
            }
         }
      }
   }
);
