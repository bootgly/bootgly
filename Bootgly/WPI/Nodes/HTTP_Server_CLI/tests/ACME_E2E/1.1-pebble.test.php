<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;

// ? Live context — set by @.php only when BOOTGLY_ACME_E2E=1 and Pebble is
//   reachable on :14000; absent → this spec skips itself
$context = $GLOBALS['BOOTGLY_ACME_PEBBLE'] ?? null;

return new Specification(
   description: 'ACME(live): order → HTTP-01 → finalize → download → install → hot swap against Pebble (requires BOOTGLY_ACME_E2E=1 + Pebble on :14000)',
   skip: $context === null,
   test: function () use ($context) {
      $Server = $context['Server'];
      $AutoTLS = $context['AutoTLS'];

      // ! TLS probe — captures the peer certificate
      $peer = static function (): null|array {
         $Context = stream_context_create([
            'ssl' => [
               'verify_peer' => false,
               'verify_peer_name' => false,
               'allow_self_signed' => true,
               'capture_peer_cert' => true
            ]
         ]);
         $Socket = @stream_socket_client(
            'ssl://127.0.0.1:8100',
            $code,
            $message,
            5,
            STREAM_CLIENT_CONNECT,
            $Context
         );
         if ($Socket === false) {
            return null;
         }

         $options = stream_context_get_options($Socket);
         fclose($Socket);

         $X509 = $options['ssl']['peer_certificate'] ?? null;
         $parsed = $X509 !== null ? openssl_x509_parse($X509) : false;

         return is_array($parsed) ? $parsed : null;
      };

      // @ 1. The bootstrap self-signed certificate serves immediately
      $certificate = $peer();

      yield assert(
         assertion: $certificate !== null
            && $certificate['subject'] === $certificate['issuer'],
         description: 'the server binds immediately on the self-signed bootstrap'
      );

      // @ 2. Full issuance against Pebble — the suite process drives the
      //   order (in production the certifier child runs this exact call);
      //   Pebble validates via the HTTP-01 helper bound on :5002
      $swapped = $AutoTLS->renew();

      yield assert(
         assertion: $swapped === true,
         description: 'renew() completes the full order against Pebble and installs the certificate'
      );
      yield assert(
         assertion: $AutoTLS->check() === true,
         description: 'the installed certificate satisfies check() (manifest committed)'
      );

      // @ The persisted fullchain carries DISTINCT leaf + intermediate
      //   blocks — a leaf-only download must not pass as a chain
      $fullchain = (string) file_get_contents((string) $AutoTLS->Certificates->certificate);
      $blocks = substr_count($fullchain, '-----BEGIN CERTIFICATE-----');
      $directory = dirname((string) $AutoTLS->Certificates->certificate);
      $leaf = (string) file_get_contents("{$directory}/certificate.pem");
      $chain = (string) file_get_contents("{$directory}/chain.pem");

      yield assert(
         assertion: $blocks >= 2 && $chain !== '' && $leaf !== $chain,
         description: 'the installed fullchain persists distinct leaf and intermediate blocks'
      );

      // @ 3. Trigger the hot swap exactly like the certifier does
      $Server->{'@swap'};
      usleep(400000);

      // @ 4. A fresh handshake presents the CA-issued certificate
      $certificate = $peer();

      yield assert(
         assertion: $certificate !== null
            && $certificate['subject'] !== $certificate['issuer']
            && stripos((string) ($certificate['issuer']['CN'] ?? ''), 'Pebble') !== false,
         description: 'a fresh TLS handshake presents the Pebble-issued certificate (no restart)'
      );

      // @ 5. The renewal lock was released
      $lock = fopen("{$AutoTLS->path}renew.lock", 'c');
      $acquired = $lock !== false && flock($lock, LOCK_EX | LOCK_NB);

      yield assert(
         assertion: $acquired === true,
         description: 'the renewal lock is released after the order settles'
      );

      if ($lock !== false) {
         flock($lock, LOCK_UN);
         fclose($lock);
      }
   }
);
