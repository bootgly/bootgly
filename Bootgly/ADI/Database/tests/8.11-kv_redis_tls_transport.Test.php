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


/**
 * H2 PoC — the async Redis driver must finish TLS before emitting RESP.
 *
 * Every leg drives the public KV command/await path against a real forked
 * loopback peer. The plaintext leg proves the capture/reply harness. The two
 * secure legs accept either a TLS ClientHello or the vulnerable raw RESP,
 * answer through the transport they observed and report only bounded facts.
 *
 * @return array{
 *    mode:string,
 *    response:mixed,
 *    client_error:string,
 *    child_exit:int,
 *    transport:string,
 *    first_hex:string,
 *    secret_seen:bool,
 *    ping_seen:bool,
 *    wire_bytes:int,
 *    wire_sha256:string,
 *    server_error:string
 * }
 */
$Run = static function (string $mode, bool $verify): array {
   $certificate = __DIR__ . '/fixtures/postgresql_tls.pem';
   $password = 'H2-redis-secret-canary';

   $Context = stream_context_create([
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
      $Context,
   );
   $Channel = stream_socket_pair(
      STREAM_PF_UNIX,
      STREAM_SOCK_STREAM,
      STREAM_IPPROTO_IP,
   );

   if (is_resource($Listener) === false || $Channel === false) {
      is_resource($Listener) && fclose($Listener);

      return [
         'mode' => $mode,
         'response' => null,
         'client_error' => 'fixture could not create listener/channel',
         'child_exit' => -1,
         'transport' => 'none',
         'first_hex' => '',
         'secret_seen' => false,
         'ping_seen' => false,
         'wire_bytes' => 0,
         'wire_sha256' => '',
         'server_error' => $error,
      ];
   }

   $address = stream_socket_get_name($Listener, false);
   $separator = is_string($address) ? strrpos($address, ':') : false;
   $port = $separator === false ? 0 : (int) substr($address, $separator + 1);

   $PID = pcntl_fork();
   if ($PID === -1 || $port < 1) {
      fclose($Channel[0]);
      fclose($Channel[1]);
      fclose($Listener);

      return [
         'mode' => $mode,
         'response' => null,
         'client_error' => 'fixture could not fork/resolve listener',
         'child_exit' => -1,
         'transport' => 'none',
         'first_hex' => '',
         'secret_seen' => false,
         'ping_seen' => false,
         'wire_bytes' => 0,
         'wire_sha256' => '',
         'server_error' => '',
      ];
   }

   if ($PID === 0) {
      fclose($Channel[0]);

      $transport = 'none';
      $first = '';
      $wire = '';
      $serverError = '';
      $Peer = @stream_socket_accept($Listener, 5.0);
      fclose($Listener);

      if (is_resource($Peer)) {
         stream_set_blocking($Peer, true);
         stream_set_timeout($Peer, 5);

         $peeked = @stream_socket_recvfrom($Peer, 1, STREAM_PEEK);
         $first = is_string($peeked) ? $peeked : '';
         $TLS = $first !== '' && ord($first[0]) === 0x16;

         if ($TLS) {
            $encrypted = @stream_socket_enable_crypto(
               $Peer,
               true,
               STREAM_CRYPTO_METHOD_TLS_SERVER,
            );

            if ($encrypted === true) {
               $transport = 'tls';
            }
            else {
               $serverError = 'TLS server handshake failed';
            }
         }
         else {
            $transport = 'plaintext';
         }

         if ($serverError === '') {
            while (! str_contains($wire, "\r\nPING\r\n")) {
               $chunk = @fread($Peer, 8192);
               if (is_string($chunk) === false || $chunk === '') {
                  $serverError = 'server did not receive a complete PING preamble';
                  break;
               }

               $wire .= $chunk;
               if (strlen($wire) > 65536) {
                  $serverError = 'server received an oversized Redis preamble';
                  break;
               }
            }
         }

         if ($serverError === '') {
            $reply = "+OK\r\n+PONG\r\n";
            if (@fwrite($Peer, $reply) !== strlen($reply)) {
               $serverError = 'server could not write the complete Redis replies';
            }
         }

         fclose($Peer);
      }
      else {
         $serverError = 'server did not accept the Redis connection';
      }

      $Report = [
         'transport' => $transport,
         'first_hex' => bin2hex($first),
         'secret_seen' => str_contains($wire, $password),
         'ping_seen' => str_contains($wire, "\r\nPING\r\n"),
         'wire_bytes' => strlen($wire),
         'wire_sha256' => hash('sha256', $wire),
         'server_error' => $serverError,
      ];
      @fwrite($Channel[1], (string) json_encode($Report));
      fclose($Channel[1]);

      exit($serverError === '' ? 0 : 1);
   }

   fclose($Channel[1]);
   fclose($Listener);

   $secure = ['mode' => $mode];
   if ($mode !== 'disable') {
      $secure += $verify
         ? [
            'peer' => '127.0.0.1',
            'cafile' => $certificate,
         ]
         : [
            'verify' => false,
            'name' => false,
         ];
   }

   $response = null;
   $clientError = '';
   $KV = new KV([
      'driver' => 'redis',
      'host' => '127.0.0.1',
      'port' => $port,
      'password' => $password,
      'database' => '0',
      'timeout' => 5.0,
      'secure' => $secure,
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

   stream_set_timeout($Channel[0], 6);
   $rawReport = stream_get_contents($Channel[0]);
   fclose($Channel[0]);
   pcntl_waitpid($PID, $status);

   $Report = is_string($rawReport)
      ? json_decode($rawReport, true)
      : null;
   if (is_array($Report) === false) {
      $Report = [];
   }

   return [
      'mode' => $mode,
      'response' => $response,
      'client_error' => $clientError,
      'child_exit' => pcntl_wifexited($status) ? pcntl_wexitstatus($status) : -1,
      'transport' => is_string($Report['transport'] ?? null)
         ? $Report['transport']
         : 'none',
      'first_hex' => is_string($Report['first_hex'] ?? null)
         ? $Report['first_hex']
         : '',
      'secret_seen' => ($Report['secret_seen'] ?? false) === true,
      'ping_seen' => ($Report['ping_seen'] ?? false) === true,
      'wire_bytes' => is_int($Report['wire_bytes'] ?? null)
         ? $Report['wire_bytes']
         : 0,
      'wire_sha256' => is_string($Report['wire_sha256'] ?? null)
         ? $Report['wire_sha256']
         : '',
      'server_error' => is_string($Report['server_error'] ?? null)
         ? $Report['server_error']
         : 'missing child report',
   ];
};


return new Test(
   description: 'KV(Redis): strict TLS modes never emit AUTH before the handshake',
   test: function () use ($Run) {
      $Control = $Run('disable', false);
      $Require = $Run('require', false);
      $Verified = $Run('verify-full', true);

      yield assert(
         assertion: $Control['response'] === 'PONG'
            && $Control['client_error'] === ''
            && $Control['child_exit'] === 0
            && $Control['transport'] === 'plaintext'
            && $Control['first_hex'] === '2a'
            && $Control['secret_seen']
            && $Control['ping_seen']
            && $Control['server_error'] === '',
         description: 'H2 control: the plaintext Redis fixture captures AUTH and completes PING; '
            . json_encode($Control),
      );

      $requireSafe = $Require['response'] === 'PONG'
         && $Require['client_error'] === ''
         && $Require['child_exit'] === 0
         && $Require['transport'] === 'tls'
         && $Require['first_hex'] === '16'
         && $Require['secret_seen']
         && $Require['ping_seen']
         && $Require['server_error'] === '';

      yield assert(
         assertion: $requireSafe,
         description: $Control['response'] === 'PONG'
            && $Require['transport'] === 'plaintext'
            && $Require['secret_seen']
            ? 'CONFIRMED H2: Redis secure=require emitted plaintext AUTH before TLS; evidence='
               . json_encode($Require)
            : 'H2 require fixture failed without proving plaintext credential exposure; evidence='
               . json_encode($Require),
      );

      yield assert(
         assertion: $Verified['response'] === 'PONG'
            && $Verified['client_error'] === ''
            && $Verified['child_exit'] === 0
            && $Verified['transport'] === 'tls'
            && $Verified['first_hex'] === '16'
            && $Verified['secret_seen']
            && $Verified['ping_seen']
            && $Verified['server_error'] === '',
         description: 'H2 TLS control: verify-full trusts the pinned fixture and carries RESP only '
            . 'inside TLS; evidence=' . json_encode($Verified),
      );
   },
);
