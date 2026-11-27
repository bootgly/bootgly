<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;
use Bootgly\WPI\Interfaces\TCP_Client_CLI;
use Bootgly\WPI\Interfaces\TCP_Client_CLI\Suspects;


return new Test(
   description: 'It should verify TLS against the configured name while dialing the resolved address',
   test: new Assertions(Case: function (): Generator {
      // ? A forked TLS upstream needs pcntl and posix
      if (function_exists('pcntl_fork') === false || function_exists('posix_kill') === false) {
         yield new Assertion(description: 'pcntl/posix are unavailable: the TLS dial by name is not measured')->skip();

         return;
      }

      // ! A test CA, and two leaves it signs: `upstream.test` (served only to that SNI) and
      //   `decoy.test` (served to any other); generated here, never stored
      $directory = Temporaries::reserve('tcp-dial-tls');
      $configuration = "{$directory}/openssl.cnf";
      file_put_contents($configuration, implode("\n", [
         '[req]', 'distinguished_name = dn', '[dn]',
         '[ca]', 'basicConstraints = critical,CA:TRUE', 'keyUsage = critical,keyCertSign,cRLSign',
         '[upstream]', 'basicConstraints = CA:FALSE', 'extendedKeyUsage = serverAuth', 'subjectAltName = DNS:upstream.test',
         '[decoy]', 'basicConstraints = CA:FALSE', 'extendedKeyUsage = serverAuth', 'subjectAltName = DNS:decoy.test',
         '',
      ]));
      $Key = ['config' => $configuration, 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'];
      $Signing = ['config' => $configuration, 'digest_alg' => 'sha256'];
      $CAKey = openssl_pkey_new($Key);
      assert($CAKey !== false);
      $CARequest = openssl_csr_new(['commonName' => 'Bootgly Test CA'], $CAKey, $Signing);
      assert($CARequest !== false && $CARequest !== true);
      $CA = openssl_csr_sign($CARequest, null, $CAKey, 1, $Signing + ['x509_extensions' => 'ca'], 1);
      assert($CA !== false);
      openssl_x509_export_to_file($CA, "{$directory}/ca.crt");
      $leaves = [];
      foreach (['upstream', 'decoy'] as $serial => $leaf) {
         $LeafKey = openssl_pkey_new($Key);
         assert($LeafKey !== false);
         $LeafRequest = openssl_csr_new(['commonName' => "{$leaf}.test"], $LeafKey, $Signing);
         assert($LeafRequest !== false && $LeafRequest !== true);
         $Leaf = openssl_csr_sign($LeafRequest, $CA, $CAKey, 1, $Signing + ['x509_extensions' => $leaf], $serial + 2);
         assert($Leaf !== false);
         openssl_x509_export_to_file($Leaf, "{$directory}/{$leaf}.crt");
         openssl_pkey_export_to_file($LeafKey, "{$directory}/{$leaf}.key", null, ['config' => $configuration]);
         $leaves[$leaf] = ['local_cert' => "{$directory}/{$leaf}.crt", 'local_pk' => "{$directory}/{$leaf}.key"];
      }

      // ! The TLS upstream on 127.0.0.1, and a plain listener on 127.0.0.2 (same port) that
      //   only a walk past a failed handshake would reach
      $Server = stream_socket_server(
         'tcp://127.0.0.1:0',
         $code,
         $message,
         STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
         stream_context_create(['ssl' => $leaves['decoy'] + ['SNI_server_certs' => ['upstream.test' => $leaves['upstream']]]])
      );
      assert($Server !== false);
      $port = (int) explode(':', (string) stream_socket_get_name($Server, false))[1];
      $Second = @stream_socket_server("tcp://127.0.0.2:{$port}", $code, $message);
      $Slow = stream_socket_server(
         'tcp://127.0.0.1:0',
         $code,
         $message,
         STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
         stream_context_create(['ssl' => $leaves['upstream']])
      );
      assert($Slow !== false);
      $slow = (int) explode(':', (string) stream_socket_get_name($Slow, false))[1];

      // ! A TLS server per listener, forked; it handshakes `$delay` microseconds after accepting,
      //   and ends itself (no shutdown hook of the runner) once its parent is gone
      $parent = posix_getpid();
      $serve = static function ($Listener, int $delay) use ($parent): int {
         $PID = pcntl_fork();
         if ($PID !== 0) {
            return $PID;
         }

         // @@ Serve handshakes until killed, or orphaned
         while (posix_getppid() === $parent) {
            $Peer = @stream_socket_accept($Listener, 1);
            if ($Peer === false) {
               continue;
            }
            if ($delay > 0) {
               usleep($delay);
            }
            if (@stream_socket_enable_crypto($Peer, true, STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | STREAM_CRYPTO_METHOD_TLSv1_3_SERVER) === true) {
               @fwrite($Peer, 'pong');
            }
            @fclose($Peer);
         }
         posix_kill(posix_getpid(), SIGKILL);

         return 0;
      };
      $PID = $serve($Server, 0);
      // ! A slow TLS upstream: its handshake starts 2.6 s after the TCP connection
      $Delayed = $PID > 0 ? $serve($Slow, 2_600_000) : -1;

      try {
         // ? A fork that failed (a process limit) fails the case: nothing to measure, nothing to kill
         yield new Assertion(
            description: 'both TLS upstreams are running',
            fallback: "A TLS upstream could not be forked: {$PID}, {$Delayed}."
         )
            ->expect($PID > 0 && $Delayed > 0)
            ->to->be(true)
            ->assert();

         $Client = new TCP_Client_CLI(TCP_Client_CLI::MODE_TEST);
         $Client->connectTimeout = 3;
         $Client->Resolution = static fn (): array => ['127.0.0.1', '127.0.0.2'];
         $TLS = ['cafile' => "{$directory}/ca.crt", 'verify_peer' => true, 'verify_peer_name' => true];

         // @ The configured name: SNI and verification use it, the dial uses the address
         $Client->configure(new TCP_Client_CLI\Configs(host: 'upstream.test', port: $port, secure: $TLS));
         $Socket = $Client->connect();
         $peer = $Socket === false ? false : stream_socket_get_name($Socket, true);
         $read = '';
         if ($Socket !== false) {
            stream_set_blocking($Socket, true);
            stream_set_timeout($Socket, 2);
            $read = (string) fread($Socket, 4);
         }

         yield new Assertion(
            description: 'a certificate for the name the resolver mapped to 127.0.0.1 verifies',
            fallback: 'The TLS dial by name failed: ' . json_encode([$peer, $read, $Client->Connections->errors])
         )
            ->expect([$peer, $read])
            ->to->be(["127.0.0.1:{$port}", 'pong'])
            ->assert();

         // @ Another name on the same address: the certificate does not match, the walk stops
         $Client->configure(new TCP_Client_CLI\Configs(host: 'other.test', port: $port, secure: $TLS));
         $Wrong = @$Client->connect();
         $walked = $Second !== false && @stream_socket_accept($Second, 0.2) !== false;

         yield new Assertion(
            description: 'a certificate for another name fails, the next address is not tried, nothing becomes a suspect',
            fallback: 'A wrong-name certificate passed, or the walk went on: ' . json_encode([$Wrong !== false, $walked])
         )
            ->expect([$Wrong, $walked, Suspects::split(['127.0.0.1', '127.0.0.2'], $port)[1]])
            ->to->be([false, false, []])
            ->assert();

         // @ Two addresses, 4.2 seconds: the first one's TCP stage gets 2.1, its handshake the
         //   remainder — a handshake that takes 2.6 seconds completes
         $Client->connectTimeout = 4.2;
         $Client->configure(new TCP_Client_CLI\Configs(host: 'upstream.test', port: $slow, secure: $TLS));
         $start = microtime(true);
         $Late = @$Client->connect();
         $elapsed = microtime(true) - $start;
         $read = '';
         if ($Late !== false) {
            stream_set_blocking($Late, true);
            stream_set_timeout($Late, 2);
            $read = (string) fread($Late, 4);
         }

         yield new Assertion(
            description: 'the TLS handshake gets the whole remainder of the budget, not the address\'s share',
            fallback: 'A slow handshake was cut at the address\'s share: ' . json_encode([$Late !== false, $read, round($elapsed, 3), $Client->Connections->Fault?->name])
         )
            ->expect([$read, $elapsed >= 2.5 && $elapsed < 4.2])
            ->to->be(['pong', true])
            ->assert();
      }
      finally {
         // ! Only a real child: `posix_kill(-1)` would signal every process of the user
         foreach ([$PID, $Delayed] as $Child) {
            if ($Child > 0) {
               posix_kill($Child, SIGKILL);
               pcntl_waitpid($Child, $status);
            }
         }
         fclose($Server);
         fclose($Slow);
         if ($Second !== false) {
            fclose($Second);
         }
         foreach (glob("{$directory}/*") ?: [] as $file) {
            unlink($file);
         }
         rmdir($directory);
      }
   })
);
