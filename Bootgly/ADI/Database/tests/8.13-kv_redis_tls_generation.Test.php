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
use Bootgly\ADI\Databases\KV\Drivers\Redis;


return new Test(
   description: 'KV(Redis): TLS trust is bound to the current socket generation',
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
         throw new RuntimeException("TLS generation fixture could not start: {$error}");
      }

      $address = stream_socket_get_name($Listener, false);
      $separator = is_string($address) ? strrpos($address, ':') : false;
      $port = $separator === false ? 0 : (int) substr($address, $separator + 1);
      $PID = pcntl_fork();
      if ($PID < 0 || $port < 1) {
         throw new RuntimeException('TLS generation fixture could not fork/resolve listener.');
      }

      if ($PID === 0) {
         fclose($Channel[0]);
         $Peer = @stream_socket_accept($Listener, 5.0);
         fclose($Listener);
         $encrypted = is_resource($Peer)
            ? @stream_socket_enable_crypto($Peer, true, STREAM_CRYPTO_METHOD_TLS_SERVER)
            : false;
         @fwrite($Channel[1], (string) json_encode(['encrypted' => $encrypted === true]));
         fclose($Channel[1]);

         if (is_resource($Peer)) {
            stream_set_timeout($Peer, 5);
            while (! feof($Peer)) {
               $chunk = @fread($Peer, 1024);
               if ($chunk === '' || $chunk === false) {
                  break;
               }
            }
            fclose($Peer);
         }

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
         throw new RuntimeException("TLS generation client could not connect: {$connectError}");
      }

      $encrypted = @stream_socket_enable_crypto($TLS, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
      $metadata = stream_get_meta_data($TLS);

      $Config = new Config([
         'driver' => 'redis',
         'password' => 'H2-generation-secret',
         'secure' => [
            'mode' => 'require',
            'verify' => false,
            'name' => false,
         ],
      ]);
      $Connection = new Connection($Config);
      $Connection->attach($TLS);
      $Redis = new Redis($Config, $Connection);

      $Plain = stream_socket_pair(
         STREAM_PF_UNIX,
         STREAM_SOCK_STREAM,
         STREAM_IPPROTO_IP,
      );
      if ($Plain === false) {
         throw new RuntimeException('TLS generation fixture could not create replacement socket.');
      }
      stream_set_blocking($Plain[0], false);
      stream_set_blocking($Plain[1], false);

      // ! Replace the exact resource the driver classified as encrypted.
      $Connection->attach($Plain[0]);
      $replacement = stream_get_meta_data($Plain[0]);
      $Operation = $Redis->command('AUTH', ['H2-generation-secret']);
      $Redis->advance($Operation);
      $leaked = (string) @fread($Plain[1], 8192);

      fclose($Plain[1]);
      if (is_resource($TLS)) {
         fclose($TLS);
      }
      pcntl_waitpid($PID, $status);
      $raw = stream_get_contents($Channel[0]);
      fclose($Channel[0]);
      $Report = is_string($raw) ? json_decode($raw, true) : null;

      yield assert(
         assertion: $encrypted === true
            && is_array($metadata['crypto'] ?? null)
            && is_array($Report)
            && ($Report['encrypted'] ?? false) === true
            && is_array($replacement['crypto'] ?? null) === false,
         description: 'H2 generation control: initial socket is TLS and replacement is plaintext'
      );

      yield assert(
         assertion: $Operation->finished
            && is_string($Operation->error)
            && str_contains($Operation->error, 'strict TLS')
            && $leaked === ''
            && $Connection->connected === false,
         description: 'H2 TLS trust must not survive a socket generation change; '
            . 'error=' . var_export($Operation->error, true)
            . '; leaked_hex=' . bin2hex($leaked),
      );
   },
);
