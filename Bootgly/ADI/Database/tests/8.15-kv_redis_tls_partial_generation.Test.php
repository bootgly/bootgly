<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Config;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Database\Operation\OperationStates;
use Bootgly\ADI\Databases\KV\Drivers\Redis;


return new Test(
   description: 'KV(Redis): a partial TLS write cannot continue on a replacement socket',
   test: function () {
      $certificate = __DIR__ . '/fixtures/postgresql_tls.pem';
      $ServerContext = stream_context_create([
         'ssl' => [
            'local_cert' => $certificate,
            'verify_peer' => false,
            'allow_self_signed' => true,
         ],
      ]);
      $Listener = stream_socket_server(
         'tcp://127.0.0.1:0',
         $errorCode,
         $error,
         STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
         $ServerContext,
      );
      $Channel = stream_socket_pair(
         STREAM_PF_UNIX,
         STREAM_SOCK_STREAM,
         STREAM_IPPROTO_IP,
      );

      if (is_resource($Listener) === false || $Channel === false) {
         throw new RuntimeException("TLS partial-generation fixture could not start: {$error}");
      }

      $address = stream_socket_get_name($Listener, false);
      $separator = is_string($address) ? strrpos($address, ':') : false;
      $port = $separator === false ? 0 : (int) substr($address, $separator + 1);
      $PID = pcntl_fork();
      if ($PID < 0 || $port < 1) {
         throw new RuntimeException('TLS partial-generation fixture could not fork/resolve listener.');
      }

      if ($PID === 0) {
         fclose($Channel[0]);
         $Peer = @stream_socket_accept($Listener, 5.0);
         fclose($Listener);
         $encrypted = is_resource($Peer)
            ? @stream_socket_enable_crypto($Peer, true, STREAM_CRYPTO_METHOD_TLS_SERVER)
            : false;
         @fwrite($Channel[1], (string) json_encode([
            'encrypted' => $encrypted === true,
         ]) . "\n");

         // @ Hold the TLS receive window full until the parent has exercised
         //   the replacement socket. Reading here would let the large SET
         //   finish and turn a security regression into a timing-dependent test.
         stream_set_timeout($Channel[1], 10);
         @fread($Channel[1], 1);

         if (is_resource($Peer)) {
            fclose($Peer);
         }
         fclose($Channel[1]);

         exit($encrypted === true ? 0 : 1);
      }

      fclose($Channel[1]);
      fclose($Listener);
      $ClientContext = stream_context_create([
         'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
         ],
      ]);
      $TLS = stream_socket_client(
         "tcp://127.0.0.1:{$port}",
         $connectCode,
         $connectError,
         5.0,
         STREAM_CLIENT_CONNECT,
         $ClientContext,
      );
      if (is_resource($TLS) === false) {
         throw new RuntimeException("TLS partial-generation client could not connect: {$connectError}");
      }

      // ! A small kernel send buffer plus a peer that does not read makes the
      //   first 16 MiB SET deterministically retain an unwritten tail.
      if (extension_loaded('sockets')) {
         $Raw = socket_import_stream($TLS);
         if ($Raw !== false) {
            @socket_set_option($Raw, SOL_SOCKET, SO_SNDBUF, 4096);
         }
      }

      $encrypted = @stream_socket_enable_crypto($TLS, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
      stream_set_blocking($TLS, false);
      stream_set_timeout($Channel[0], 6);
      $rawReport = fgets($Channel[0]);
      $Report = is_string($rawReport) ? json_decode($rawReport, true) : null;

      $Config = new Config([
         'driver' => 'redis',
         'secure' => [
            'mode' => 'require',
            'verify' => false,
            'name' => false,
         ],
      ]);
      $Connection = new Connection($Config);
      $Connection->attach($TLS);
      $Redis = new Redis($Config, $Connection);
      $canary = 'H2-SWAP-CANARY-';
      $payload = str_repeat($canary, 1024 * 1024);
      $Operation = $Redis->command('SET', ['h2:partial-generation', $payload]);
      $Redis->advance($Operation);
      $stateBefore = $Operation->state;
      $remainingBefore = strlen($Operation->write);
      $partial = $stateBefore === OperationStates::Querying
         && $Operation->finished === false
         && $remainingBefore > 0;

      $Plain = stream_socket_pair(
         STREAM_PF_UNIX,
         STREAM_SOCK_STREAM,
         STREAM_IPPROTO_IP,
      );
      if ($Plain === false) {
         throw new RuntimeException('TLS partial-generation fixture could not create replacement socket.');
      }
      stream_set_blocking($Plain[0], false);
      stream_set_blocking($Plain[1], false);

      // ! Replace the socket only after a TLS frame was partially written.
      $Connection->attach($Plain[0]);
      $Redis->advance($Operation);
      $leaked = (string) @fread($Plain[1], 8192);
      $remainingAfter = strlen($Operation->write);

      @fwrite($Channel[0], "x");
      fclose($Channel[0]);
      if (is_resource($Plain[1])) {
         fclose($Plain[1]);
      }
      if (is_resource($TLS)) {
         fclose($TLS);
      }
      pcntl_waitpid($PID, $status);

      yield assert(
         assertion: $encrypted === true
            && is_array($Report)
            && ($Report['encrypted'] ?? false) === true
            && $partial,
         description: 'H2 partial-generation control must retain a TLS write tail; '
            . 'state=' . $stateBefore->value
            . '; remaining=' . $remainingBefore
            . '; server=' . json_encode($Report),
      );

      $safe = $Operation->finished
         && is_string($Operation->error)
         && str_contains($Operation->error, 'strict TLS')
         && $Connection->connected === false
         && $leaked === '';

      yield assert(
         assertion: $safe,
         description: str_contains($leaked, $canary)
            ? 'CONFIRMED H2: strict Redis partial write continued on a replacement plaintext socket; evidence='
               . json_encode([
                  'state_before' => $stateBefore->value,
                  'remaining_before' => $remainingBefore,
                  'remaining_after' => $remainingAfter,
                  'leaked_bytes' => strlen($leaked),
                  'canary_seen' => true,
               ])
            : 'H2 partial-generation path failed without proving plaintext leakage; evidence='
               . json_encode([
                  'finished' => $Operation->finished,
                  'error' => $Operation->error,
                  'connected' => $Connection->connected,
                  'remaining_before' => $remainingBefore,
                  'remaining_after' => $remainingAfter,
                  'leaked_bytes' => strlen($leaked),
               ]),
      );
   },
);
