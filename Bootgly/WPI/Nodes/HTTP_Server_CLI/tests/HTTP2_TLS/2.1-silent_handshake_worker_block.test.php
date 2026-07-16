<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;


/**
 * H3 PoC — a silent TCP peer must not monopolize the TLS worker.
 *
 * The suite boots exactly one real Bootgly TLS worker. A valid TLS control is
 * completed first. The attack then opens a raw TCP connection and sends no
 * ClientHello; while that socket remains open, a second client sends a real
 * ClientHello through nonblocking `stream_socket_enable_crypto()`. A secure
 * event-driven handshake lets the valid client complete independently. The
 * vulnerable synchronous path completes it only after the silent peer finally
 * sends its own ClientHello.
 */

return new Specification(
   description: 'A silent TLS handshake must not block a valid client on the same worker',

   test: function (): bool|string {
      $baseline = null;
      $silent = null;
      $valid = null;

      $Open = static function (): array {
         $context = stream_context_create([
            'ssl' => [
               'verify_peer' => false,
               'verify_peer_name' => false,
               'allow_self_signed' => true,
               'peer_name' => 'localhost',
               'alpn_protocols' => 'http/1.1',
            ],
         ]);
         $socket = @stream_socket_client(
            'tcp://127.0.0.1:8086',
            $errorNumber,
            $errorMessage,
            2,
            STREAM_CLIENT_CONNECT,
            $context,
         );

         return [
            'socket' => $socket,
            'error' => "{$errorNumber} {$errorMessage}",
         ];
      };

      $Negotiate = static function ($socket, int $budgetNS): array {
         $startedNS = (int) hrtime(true);
         $deadlineNS = $startedNS + $budgetNS;
         $negotiated = 0;

         do {
            $negotiated = @stream_socket_enable_crypto(
               $socket,
               true,
               STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
                  | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
            );
            if ($negotiated === true || $negotiated === false) {
               break;
            }

            usleep(5_000);
         }
         while ((int) hrtime(true) < $deadlineNS);

         return [
            'state' => $negotiated,
            'elapsed_ms' => ((int) hrtime(true) - $startedNS) / 1_000_000,
         ];
      };

      $Exchange = static function ($socket, string $path): array {
         $request = "GET {$path} HTTP/1.1\r\n"
            . "Host: localhost:8086\r\n"
            . "Connection: close\r\n"
            . "\r\n";
         $written = @fwrite($socket, $request);
         $response = '';
         $startedNS = (int) hrtime(true);
         $deadlineNS = $startedNS + 1_500_000_000;

         while ((int) hrtime(true) < $deadlineNS) {
            $chunk = @fread($socket, 65536);
            if ($chunk !== false && $chunk !== '') {
               $response .= $chunk;
               if (str_contains($response, "uri={$path}")) {
                  break;
               }
               continue;
            }

            usleep(5_000);
         }

         return [
            'written' => $written,
            'response' => $response,
            'elapsed_ms' => ((int) hrtime(true) - $startedNS) / 1_000_000,
         ];
      };

      try {
         // @ A/B control: prove that the certificate, client context, route,
         //   and single worker all function before the silent peer exists.
         $opened = $Open();
         $baseline = $opened['socket'];
         if (! is_resource($baseline)) {
            return 'TLS control could not open its TCP socket: ' . $opened['error'];
         }
         stream_set_blocking($baseline, false);

         $baselineHandshake = $Negotiate($baseline, 1_500_000_000);
         if ($baselineHandshake['state'] !== true) {
            return 'TLS control did not negotiate: ' . json_encode($baselineHandshake);
         }
         $baselineExchange = $Exchange($baseline, '/h3-control');
         if (! str_contains($baselineExchange['response'], 'uri=/h3-control')) {
            return 'TLS control did not receive the routed response: '
               . json_encode($baselineExchange);
         }
         @fclose($baseline);

         // @ Attack: connect at TCP only and send no ClientHello. `$Open`
         //   attaches a client TLS context but does not start crypto, allowing
         //   this same peer to release the worker through a valid ClientHello
         //   after the observation window (without entering the H4 failure path).
         $opened = $Open();
         $silent = $opened['socket'];
         if (! is_resource($silent)) {
            return 'Silent peer could not connect: ' . $opened['error'];
         }
         stream_set_blocking($silent, false);

         // Give the event loop time to accept the first queued peer and enter
         // Connection::__construct()->handshake().
         usleep(150_000);

         $opened = $Open();
         $valid = $opened['socket'];
         if (! is_resource($valid)) {
            return 'Valid attack-control client could not connect: ' . $opened['error'];
         }
         stream_set_blocking($valid, false);

         $duringSilent = $Negotiate($valid, 750_000_000);
         if ($duringSilent['state'] === true) {
            $servedDuringSilent = $Exchange($valid, '/h3-concurrent');

            return str_contains($servedDuringSilent['response'], 'uri=/h3-concurrent')
               ? true
               : 'TLS negotiated while the silent peer was held, but the request was not served: '
                  . json_encode($servedDuringSilent);
         }
         if ($duringSilent['state'] === false) {
            return 'Valid TLS negotiation failed while the silent peer was held; '
               . 'worker-blocking could not be isolated: ' . json_encode($duringSilent);
         }

         // Release the blocked server-side handshake by sending a valid
         // ClientHello from the same peer. This avoids the failed-handshake
         // cleanup path covered separately by H4.
         $silentRelease = $Negotiate($silent, 2_000_000_000);
         if ($silentRelease['state'] !== true) {
            return 'Silent peer could not release the worker with a valid ClientHello: '
               . json_encode([
                  'during_silent' => $duringSilent,
                  'silent_release' => $silentRelease,
               ]);
         }

         // The listener can immediately accept the already queued valid peer;
         // finish that TLS exchange before either client sends HTTP, otherwise
         // the synchronous server handshake blocks request processing again.
         $afterRelease = $Negotiate($valid, 2_000_000_000);
         if ($afterRelease['state'] !== true) {
            return 'Queued valid TLS client did not negotiate after the original peer '
               . 'sent its ClientHello: '
               . json_encode([
                  'during_silent' => $duringSilent,
                  'silent_release' => $silentRelease,
                  'queued_after_release' => $afterRelease,
               ]);
         }

         $silentExchange = $Exchange($silent, '/h3-release');
         if (! str_contains($silentExchange['response'], 'uri=/h3-release')) {
            return 'Released silent peer did not receive its routed response: '
               . json_encode($silentExchange);
         }
         $recoveryExchange = $Exchange($valid, '/h3-recovery');
         if (! str_contains($recoveryExchange['response'], 'uri=/h3-recovery')) {
            return 'Recovered TLS client did not receive its routed response: '
               . json_encode($recoveryExchange);
         }

         return 'CONFIRMED: one silent TCP peer blocked the single TLS worker for the '
            . 'entire observation window; the waiting valid client negotiated and was '
            . 'served only after that peer finally sent its ClientHello. Evidence: '
            . json_encode([
               'baseline_handshake' => $baselineHandshake,
               'baseline_exchange' => $baselineExchange,
               'during_silent' => $duringSilent,
               'silent_release' => $silentRelease,
               'silent_exchange' => $silentExchange,
               'queued_after_release' => $afterRelease,
               'recovery_exchange' => $recoveryExchange,
            ]);
      }
      finally {
         foreach ([$baseline, $silent, $valid] as $socket) {
            if (is_resource($socket)) {
               @fclose($socket);
            }
         }
      }
   }
);
