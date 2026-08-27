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
use Bootgly\ADI\Databases\KV;


return new Test(
   description: 'KV(Redis): prefer attempts TLS before reconnecting in plaintext',
   test: function () {
      $password = 'H2-prefer-secret-canary';
      $Listener = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $error);
      $Channel = stream_socket_pair(
         STREAM_PF_UNIX,
         STREAM_SOCK_STREAM,
         STREAM_IPPROTO_IP,
      );

      if (is_resource($Listener) === false || $Channel === false) {
         throw new RuntimeException("Prefer fixture could not start: {$error}");
      }

      $address = stream_socket_get_name($Listener, false);
      $separator = is_string($address) ? strrpos($address, ':') : false;
      $port = $separator === false ? 0 : (int) substr($address, $separator + 1);
      $PID = pcntl_fork();
      if ($PID < 0 || $port < 1) {
         throw new RuntimeException('Prefer fixture could not fork/resolve its listener.');
      }

      if ($PID === 0) {
         fclose($Channel[0]);
         $first = '';
         $second = '';
         $wire = '';
         $connections = 0;
         $serverError = '';

         $Peer = @stream_socket_accept($Listener, 5.0);
         if (is_resource($Peer)) {
            $connections++;
            stream_set_blocking($Peer, true);
            stream_set_timeout($Peer, 5);
            $peeked = @stream_socket_recvfrom($Peer, 1, STREAM_PEEK);
            $first = is_string($peeked) ? $peeked : '';

            if ($first !== '' && ord($first[0]) === 0x16) {
               // ! Refuse the preferred TLS generation before any application
               //   data; the client must reconnect before plaintext fallback.
               fclose($Peer);
               $Peer = @stream_socket_accept($Listener, 5.0);

               if (is_resource($Peer)) {
                  $connections++;
                  stream_set_blocking($Peer, true);
                  stream_set_timeout($Peer, 5);
                  $peeked = @stream_socket_recvfrom($Peer, 1, STREAM_PEEK);
                  $second = is_string($peeked) ? $peeked : '';
               }
               else {
                  $serverError = 'plaintext fallback did not reconnect';
               }
            }
            else {
               // ? Vulnerable/control path: answer the first plaintext socket
               //   so the assertion fails with evidence instead of timing out.
               $second = $first;
            }

            if (is_resource($Peer) && $serverError === '') {
               while (! str_contains($wire, "\r\nPING\r\n")) {
                  $chunk = @fread($Peer, 8192);
                  if (is_string($chunk) === false || $chunk === '') {
                     $serverError = 'plaintext generation did not send PING';
                     break;
                  }
                  $wire .= $chunk;

                  if (strlen($wire) > 65536) {
                     $serverError = 'plaintext fallback preamble was oversized';
                     break;
                  }
               }

               if ($serverError === '') {
                  $reply = "+OK\r\n+PONG\r\n";
                  if (@fwrite($Peer, $reply) !== strlen($reply)) {
                     $serverError = 'plaintext fallback reply was truncated';
                  }
               }

               fclose($Peer);
            }
         }
         else {
            $serverError = 'preferred TLS generation did not connect';
         }

         fclose($Listener);
         $auth = strpos($wire, 'AUTH');
         $ping = strpos($wire, 'PING');
         $Report = [
            'connections' => $connections,
            'first_hex' => bin2hex($first),
            'second_hex' => bin2hex($second),
            'secret_seen' => str_contains($wire, $password),
            'ping_seen' => $ping !== false,
            'auth_before_ping' => $auth !== false && $ping !== false && $auth < $ping,
            'server_error' => $serverError,
         ];
         @fwrite($Channel[1], (string) json_encode($Report));
         fclose($Channel[1]);

         exit($serverError === '' ? 0 : 1);
      }

      fclose($Channel[1]);
      fclose($Listener);

      $response = null;
      $clientError = '';
      $KV = new KV([
         'driver' => 'redis',
         'host' => '127.0.0.1',
         'port' => $port,
         'password' => $password,
         'database' => '0',
         'timeout' => 5.0,
         'secure' => [
            'mode' => 'prefer',
            'verify' => false,
            'name' => false,
         ],
         'pool' => ['max' => 1],
      ]);

      try {
         $Operation = $KV->await($KV->command('PING'));
         $response = $Operation->response;
      }
      catch (Throwable $Throwable) {
         $clientError = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         $KV->Connection->disconnect();
      }

      pcntl_waitpid($PID, $status);
      $raw = stream_get_contents($Channel[0]);
      fclose($Channel[0]);
      $Report = is_string($raw) ? json_decode($raw, true) : null;
      $Report = is_array($Report) ? $Report : [];

      yield assert(
         assertion: $response === 'PONG'
            && $clientError === ''
            && pcntl_wifexited($status)
            && pcntl_wexitstatus($status) === 0
            && ($Report['connections'] ?? 0) === 2
            && ($Report['first_hex'] ?? '') === '16'
            && ($Report['second_hex'] ?? '') === '2a'
            && ($Report['secret_seen'] ?? false) === true
            && ($Report['ping_seen'] ?? false) === true
            && ($Report['auth_before_ping'] ?? false) === true
            && ($Report['server_error'] ?? '') === '',
         description: 'H2 prefer must attempt TLS and reconnect before plaintext fallback; '
            . 'client_error=' . $clientError . '; evidence=' . json_encode($Report),
      );
   },
);
