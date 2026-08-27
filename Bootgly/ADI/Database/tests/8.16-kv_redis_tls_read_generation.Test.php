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
   description: 'KV(Redis): a TLS reply cannot arrive through a replacement socket',
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
         throw new RuntimeException("TLS read-generation fixture could not start: {$error}");
      }

      $address = stream_socket_get_name($Listener, false);
      $separator = is_string($address) ? strrpos($address, ':') : false;
      $port = $separator === false ? 0 : (int) substr($address, $separator + 1);
      $PID = pcntl_fork();
      if ($PID < 0 || $port < 1) {
         throw new RuntimeException('TLS read-generation fixture could not fork/resolve listener.');
      }

      if ($PID === 0) {
         fclose($Channel[0]);
         $Peer = @stream_socket_accept($Listener, 5.0);
         fclose($Listener);
         $encrypted = is_resource($Peer)
            ? @stream_socket_enable_crypto($Peer, true, STREAM_CRYPTO_METHOD_TLS_SERVER)
            : false;
         $wire = '';

         if (is_resource($Peer) && $encrypted === true) {
            stream_set_timeout($Peer, 5);
            while (! str_contains($wire, "\r\nGET\r\n")) {
               $chunk = @fread($Peer, 8192);
               if (is_string($chunk) === false || $chunk === '') {
                  break;
               }
               $wire .= $chunk;

               if (strlen($wire) > 65536) {
                  break;
               }
            }
         }

         $requestSeen = str_contains($wire, "\r\nGET\r\n")
            && str_contains($wire, 'h2:read-generation');
         @fwrite($Channel[1], (string) json_encode([
            'encrypted' => $encrypted === true,
            'request_seen' => $requestSeen,
            'wire_sha256' => hash('sha256', $wire),
         ]) . "\n");

         // @ Withhold the real Redis reply while the parent tries to inject a
         //   plaintext answer through another socket generation.
         stream_set_timeout($Channel[1], 10);
         @fread($Channel[1], 1);

         if (is_resource($Peer)) {
            fclose($Peer);
         }
         fclose($Channel[1]);

         exit($encrypted === true && $requestSeen ? 0 : 1);
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
         throw new RuntimeException("TLS read-generation client could not connect: {$connectError}");
      }

      $encrypted = @stream_socket_enable_crypto($TLS, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
      stream_set_blocking($TLS, false);
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
      $Operation = $Redis->command('GET', ['h2:read-generation']);
      $Redis->advance($Operation);
      $stateBefore = $Operation->state;

      stream_set_timeout($Channel[0], 6);
      $rawReport = fgets($Channel[0]);
      $Report = is_string($rawReport) ? json_decode($rawReport, true) : null;
      $Plain = stream_socket_pair(
         STREAM_PF_UNIX,
         STREAM_SOCK_STREAM,
         STREAM_IPPROTO_IP,
      );
      if ($Plain === false) {
         throw new RuntimeException('TLS read-generation fixture could not create replacement socket.');
      }
      stream_set_blocking($Plain[0], false);
      stream_set_blocking($Plain[1], false);

      $Connection->attach($Plain[0]);
      @fwrite($Plain[1], "+H2-FORGED-VALUE\r\n");
      $Redis->advance($Operation);
      $connected = $Connection->connected;

      @fwrite($Channel[0], "x");
      fclose($Channel[0]);
      if (is_resource($Plain[1])) {
         fclose($Plain[1]);
      }
      if (is_resource($TLS)) {
         fclose($TLS);
      }
      $Connection->disconnect();
      pcntl_waitpid($PID, $status);

      yield assert(
         assertion: $encrypted === true
            && $stateBefore === OperationStates::Reading
            && is_array($Report)
            && ($Report['encrypted'] ?? false) === true
            && ($Report['request_seen'] ?? false) === true
            && pcntl_wifexited($status)
            && pcntl_wexitstatus($status) === 0,
         description: 'H2 read-generation control must withhold a real TLS reply; '
            . 'state=' . $stateBefore->value
            . '; server=' . json_encode($Report),
      );

      $safe = $Operation->finished
         && $Operation->response === null
         && is_string($Operation->error)
         && str_contains($Operation->error, 'strict TLS')
         && $connected === false;

      yield assert(
         assertion: $safe,
         description: $Operation->response === 'H2-FORGED-VALUE'
            ? 'CONFIRMED H2: strict Redis accepted a forged plaintext response after socket generation replacement; evidence='
               . json_encode([
                  'state_before' => $stateBefore->value,
                  'response' => $Operation->response,
                  'error' => $Operation->error,
                  'connected' => $connected,
               ])
            : 'H2 read-generation path failed without proving forged plaintext acceptance; evidence='
               . json_encode([
                  'state_before' => $stateBefore->value,
                  'response' => $Operation->response,
                  'error' => $Operation->error,
                  'connected' => $connected,
               ]),
      );
   },
);
