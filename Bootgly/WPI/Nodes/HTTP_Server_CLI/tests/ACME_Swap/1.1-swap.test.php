<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\Challenges;

return new Specification(
   description: 'ACME(E2E): self-signed bootstrap serves, HTTP-01 helper answers, hot swap lands without restart',
   test: function () {
      $Server = $GLOBALS['BOOTGLY_ACME_SWAP']['Server'];

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
            'ssl://127.0.0.1:8099',
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
            && ($certificate['subject']['CN'] ?? null) === 'localhost'
            && $certificate['subject'] === $certificate['issuer'],
         description: 'the first boot serves the self-signed bootstrap (CN localhost)'
      );

      // @ 2. The HTTP-01 helper answers tokens + redirects on the gate port
      Challenges::save('swap-e2e-token', 'swap-e2e-token.thumbprint');

      $fetch = static function (string $target): array {
         $Socket = @stream_socket_client('tcp://127.0.0.1:8078', $code, $message, 5);
         if ($Socket === false) {
            return ['code' => 0, 'headers' => '', 'body' => ''];
         }
         stream_set_timeout($Socket, 5);
         fwrite($Socket, "GET {$target} HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
         $raw = '';
         while (feof($Socket) === false) {
            $chunk = fread($Socket, 8192);
            if ($chunk === false || $chunk === '') {
               break;
            }
            $raw .= $chunk;
         }
         fclose($Socket);

         $split = strpos($raw, "\r\n\r\n");
         preg_match('/^HTTP\/\S+ (\d+)/', $raw, $matches);

         return [
            'code' => (int) ($matches[1] ?? 0),
            'headers' => $split !== false ? substr($raw, 0, $split) : $raw,
            'body' => $split !== false ? substr($raw, $split + 4) : ''
         ];
      };

      $response = $fetch('/.well-known/acme-challenge/swap-e2e-token');

      yield assert(
         assertion: $Server->helperReady
            && $response['code'] === 200
            && $response['body'] === 'swap-e2e-token.thumbprint',
         description: 'the ready port helper answers a known token with the key authorization'
      );

      // One deliberately incomplete request must not monopolize the helper.
      $Slow = stream_socket_client('tcp://127.0.0.1:8078', $code, $message, 5);
      if ($Slow !== false) {
         fwrite($Slow, "GET /slow HTTP/1.1\r\nHost: localhost\r\nX-Hold: ");
      }
      $started = microtime(true);
      $parallel = $fetch('/.well-known/acme-challenge/swap-e2e-token');
      $elapsed = microtime(true) - $started;
      is_resource($Slow) && fclose($Slow);

      yield assert(
         assertion: $parallel['code'] === 200
            && $parallel['body'] === 'swap-e2e-token.thumbprint'
            && $elapsed < 1.5,
         description: 'a slow incomplete client does not block a concurrent HTTP-01 validation'
      );

      $response = $fetch('/some/page');

      yield assert(
         assertion: $response['code'] === 308
            && stripos($response['headers'], 'Location: https://localhost:8099/some/page') !== false,
         description: 'the port helper redirects non-ACME traffic to HTTPS (308)'
      );

      // The watchdog replaces a dead (including zombie) helper and waits for
      // the new child's explicit readiness acknowledgement.
      $oldHelper = $Server->helper;
      posix_kill($oldHelper, SIGKILL);
      usleep(100000);
      $Watched = new ReflectionProperty($Server, 'watched');
      $Checked = new ReflectionProperty($Server, 'checked');
      $Watched->setValue($Server, 0);
      $Checked->setValue($Server, time() + 3600);
      $Supervise = new ReflectionMethod($Server, 'supervise');
      $Supervise->invoke($Server);

      yield assert(
         assertion: $Server->helperReady
            && $Server->helper > 1
            && $Server->helper !== $oldHelper
            && posix_kill($Server->helper, 0),
         description: 'the helper watchdog respawns a dead child and confirms readiness before renewal'
      );

      Challenges::drop('swap-e2e-token');

      // ! Certificate factory — CN + SAN, self-signed, 90 days
      $issue = static function (string $CN, string $SAN): array {
         $configuration = (string) tempnam(sys_get_temp_dir(), 'bootgly-acme-swap-cnf-');
         file_put_contents($configuration, <<<INI
         [req]
         distinguished_name = dn
         req_extensions = extensions
         [dn]
         [extensions]
         subjectAltName = {$SAN}
         INI);

         $Key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
         $Request = openssl_csr_new(
            ['commonName' => $CN],
            $Key,
            ['digest_alg' => 'sha256', 'config' => $configuration, 'req_extensions' => 'extensions']
         );
         $X509 = openssl_csr_sign(
            $Request, null, $Key, 90,
            ['digest_alg' => 'sha256', 'config' => $configuration, 'x509_extensions' => 'extensions']
         );

         $leaf = '';
         $private = '';
         openssl_x509_export($X509, $leaf);
         openssl_pkey_export($Key, $private);
         unlink($configuration);

         return [$leaf, $private];
      };

      // @ 3. A certificate that does NOT cover the configured domains is
      //   refused at activation — the swap never lands (round-5 guard)
      [$leaf, $private] = $issue('intruder.test', 'DNS:intruder.test');

      $Server->AutoTLS->Certificates->install($leaf, $private);

      // Drive the production master path: validate/publish generation, relay,
      // then let the worker acknowledge its local context application.
      $Server->{'@swap'};
      usleep(400000);

      $certificate = $peer();

      yield assert(
         assertion: $certificate !== null
            && ($certificate['subject']['CN'] ?? null) === 'localhost',
         description: 'a swap whose certificate does not cover the configured domains is refused'
      );

      // @ 4. Install a distinguishable certificate that COVERS the domains
      //   (stands in for the CA issuance) and trigger the hot swap exactly
      //   like the certifier does — through the SERVER's identity-bound store
      [$leaf, $private] = $issue('swapped.localhost', 'DNS:localhost,DNS:swapped.localhost');

      $Server->AutoTLS->Certificates->install($leaf, $private);

      $Server->{'@swap'};
      usleep(400000);

      // @ 5. A fresh handshake presents the swapped certificate — no restart
      $certificate = $peer();

      yield assert(
         assertion: $certificate !== null
            && ($certificate['subject']['CN'] ?? null) === 'swapped.localhost',
         description: 'a fresh TLS handshake presents the swapped certificate (no restart)'
      );

      $Snapshot = $Server->AutoTLS->snapshot(allowBootstrap: false);
      $desired = $Server->AutoTLS->Swaps->fetch();
      $acks = $Snapshot !== null && $desired !== null
         ? $Server->AutoTLS->Swaps->collect($Snapshot->generation, $desired['attempt'])
         : [];
      $converged = $Snapshot !== null;
      foreach ($Server->Process->Children->PIDs as $PID) {
         $ack = $acks[$PID] ?? null;
         $converged = $converged
            && is_array($ack)
            && $ack['success'] === true
            && $ack['certificateHash'] === $Snapshot->certificateHash
            && $ack['keyHash'] === $Snapshot->keyHash;
      }

      yield assert(
         assertion: $converged,
         description: 'every worker acknowledges the exact certificate generation, attempt and hashes it applied'
      );

      // @ 6. Mutating both acknowledged STORE paths without a manifest,
      //   request, signal or swap cannot mutate the workers' private active
      //   artifacts. Fresh handshakes must keep the acknowledged identity.
      $stable = false;
      $reforked = false;
      $recovered = false;
      $swept = false;
      if ($Snapshot !== null && $Snapshot->key !== null) {
         $originalCertificate = file_get_contents($Snapshot->certificate);
         $originalKey = file_get_contents($Snapshot->key);
         try {
            [$mutatedCertificate, $mutatedKey] = $issue(
               'mutated.localhost',
               'DNS:localhost,DNS:mutated.localhost'
            );
            file_put_contents($Snapshot->certificate, $mutatedCertificate);
            file_put_contents($Snapshot->key, $mutatedKey);
            $certificate = $peer();
            $stable = $certificate !== null
               && ($certificate['subject']['CN'] ?? null) === 'swapped.localhost';

            // A replacement worker inherits the converged master's retained
            // artifact. It must recover even while the mutable store contains
            // unrelated bytes, without adopting those bytes.
            $PIDsBefore = $Server->Process->Children->PIDs;
            $dead = reset($PIDsBefore);
            if (is_int($dead) && $dead > 1) {
               posix_kill($dead, SIGKILL);
               $deadline = microtime(true) + 4.0;
               do {
                  pcntl_signal_dispatch();
                  $PIDsAfter = $Server->Process->Children->PIDs;
                  $new = array_values(array_diff($PIDsAfter, $PIDsBefore));
                  if (count($PIDsAfter) === count($PIDsBefore) && $new !== []) {
                     $reforked = true;
                     $certificate = $peer();
                     $stale = glob(
                        rtrim(sys_get_temp_dir(), '/') . "/bootgly-tls-{$dead}-*"
                     ) ?: [];
                     $recovered = $certificate !== null
                        && ($certificate['subject']['CN'] ?? null) === 'swapped.localhost';
                     $swept = $stale === [];
                     break;
                  }
                  usleep(50000);
               } while (microtime(true) < $deadline);
            }
         }
         finally {
            is_string($originalCertificate)
               && file_put_contents($Snapshot->certificate, $originalCertificate);
            is_string($originalKey)
               && file_put_contents($Snapshot->key, $originalKey);
         }
      }
      yield assert(
         assertion: $stable,
         description: 'post-ACK mutation of the certificate store cannot change the credential served by fresh handshakes'
      );
      yield assert(
         assertion: $reforked,
         description: 'the master reforks a hard-killed worker'
      );
      yield assert(
         assertion: $recovered,
         description: 'a replacement worker recovers from the retained active artifact without trusting post-ACK store mutations'
      );
      yield assert(
         assertion: $swept,
         description: 'the dead worker private credential artifact is swept once it is reaped'
      );

      // @ 7. A permanently missing worker acknowledgement exhausts the
      //   bounded retry budget and queues the promised SIGUSR2 fallback. Test
      //   mode records the decision without replacing this test harness.
      $ChildrenProperty = new ReflectionProperty($Server->Process->Children, 'PIDs');
      $PIDs = $Server->Process->Children->PIDs;
      $missing = 999999;
      while (posix_kill($missing, 0)) {
         $missing++;
      }
      $ChildrenProperty->setValue(
         $Server->Process->Children,
         $PIDs + [count($PIDs) => $missing]
      );
      $previousTimeout = HTTP_Server_CLI::$swapAckTimeout;
      $previousRetries = HTTP_Server_CLI::$swapAckRetries;
      $previousGeneration = $Server->AutoTLS->Swaps->resolve();

      try {
         HTTP_Server_CLI::$swapAckTimeout = 0;
         HTTP_Server_CLI::$swapAckRetries = 0;
         [$leaf, $private] = $issue(
            'fallback.localhost',
            'DNS:localhost,DNS:fallback.localhost'
         );
         $Server->AutoTLS->Certificates->install($leaf, $private);
         $Server->{'@swap'};
         usleep(300000);

         $Reconcile = new ReflectionMethod($Server, 'reconcile');
         $Reconcile->invoke($Server);
         $Pending = new ReflectionProperty($Server, 'PendingSnapshot');
         $Fallback = new ReflectionProperty($Server, 'swapFallbackQueued');

         yield assert(
            assertion: $Pending->getValue($Server) === null
               && $Fallback->getValue($Server) === true
               && $Server->AutoTLS->Swaps->resolve() === $previousGeneration,
            description: 'a missing worker acknowledgement queues the bounded reload fallback without marking the generation applied'
         );
      }
      finally {
         HTTP_Server_CLI::$swapAckTimeout = $previousTimeout;
         HTTP_Server_CLI::$swapAckRetries = $previousRetries;
         $ChildrenProperty->setValue($Server->Process->Children, $PIDs);
      }
   }
);
