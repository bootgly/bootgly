<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Database;


use const STREAM_CRYPTO_METHOD_TLS_SERVER;
use const STREAM_SERVER_BIND;
use const STREAM_SERVER_LISTEN;
use function assert;
use function extension_loaded;
use function fclose;
use function is_resource;
use function is_string;
use function json_encode;
use function microtime;
use function str_contains;
use function stream_context_create;
use function stream_select;
use function stream_set_blocking;
use function stream_socket_accept;
use function stream_socket_enable_crypto;
use function stream_socket_get_name;
use function stream_socket_server;
use function strrpos;
use function substr;
use function usleep;
use Throwable;

use Bootgly\ACI\Tests\Suite\Test;


/**
 * A stock MySQL 8 — and any PostgreSQL or Redis behind a snakeoil certificate —
 * offers TLS with a certificate no trust store signed. The shipped default
 * `prefer` must complete that handshake: it encrypts opportunistically and
 * verifies nothing, and so does `require`; verification is what `verify-ca`,
 * `verify-full` and an explicit `verify` add. Both ends of the handshake are
 * driven from this process over non-blocking sockets, so the fixture needs
 * no fork.
 */
$handshake = static function (array $secure): array {
   $result = ['encrypted' => null, 'error' => '', 'SSL' => []];

   // ! A TLS listener presenting the suite's self-signed certificate
   $Context = stream_context_create(['ssl' => [
      'local_cert' => __DIR__ . '/fixtures/postgresql_tls.pem',
   ]]);
   $Listener = stream_socket_server(
      'tcp://127.0.0.1:0',
      $errorCode,
      $error,
      STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
      $Context,
   );
   if (is_resource($Listener) === false) {
      $result['error'] = 'fixture could not listen';

      return $result;
   }
   $address = stream_socket_get_name($Listener, false);
   $separator = is_string($address) ? strrpos($address, ':') : false;
   $port = $separator === false ? 0 : (int) substr($address, $separator + 1);

   $config = ['host' => '127.0.0.1', 'port' => $port, 'timeout' => 2.0];
   if ($secure !== []) {
      $config['secure'] = $secure;
   }
   $Connection = new Connection(new Config($config));
   $Peer = null;
   try {
      $Connection->connect();
      $result['SSL'] = $Connection->SSL;
      $Peer = @stream_socket_accept($Listener, 2.0);
      if (is_resource($Peer) === false) {
         $result['error'] = 'fixture accepted no connection';

         return $result;
      }
      stream_set_blocking($Peer, false);

      // ! The client's connect is asynchronous: wait for it before the ClientHello
      $read = [];
      $write = [$Connection->socket];
      $except = [];
      @stream_select($read, $write, $except, 2, 0);

      // @@ Drive both ends until the client settles: encrypted, refused or thrown
      $server = 0;
      $deadline = microtime(true) + 3.0;
      while (microtime(true) < $deadline) {
         $client = $Connection->encrypt();
         if ($server !== true) {
            $server = @stream_socket_enable_crypto($Peer, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
         }
         if ($client === true) {
            $result['encrypted'] = true;
            break;
         }
         if ($client === false) {
            $result['encrypted'] = false;
            $result['error'] = $Connection->refusal;
            break;
         }
         usleep(2_000);
      }
   }
   catch (Throwable $Throwable) {
      $result['error'] = $Throwable->getMessage();
   }
   finally {
      if (is_resource($Peer)) {
         fclose($Peer);
      }
      fclose($Listener);
      $Connection->disconnect();
   }

   return $result;
};

return new Test(
   description: 'Database: prefer and require complete a TLS handshake against a self-signed certificate; verify-ca, verify-full and an explicit verify refuse it',
   skip: extension_loaded('openssl') === false,
   test: function () use ($handshake) {
      $default = $handshake([]);
      $require = $handshake(['mode' => Config::SECURE_REQUIRE]);

      yield assert(
         assertion: $default['encrypted'] === true
            && ($default['SSL']['verify_peer'] ?? null) === false
            && ($default['SSL']['verify_peer_name'] ?? null) === false,
         description: 'The shipped default (prefer) encrypts against a self-signed certificate, verifying neither chain nor name — ' . json_encode($default)
      );
      yield assert(
         assertion: $require['encrypted'] === true,
         description: '`require` encrypts against the same certificate — ' . json_encode($require)
      );

      foreach ([
         'verify-ca' => ['mode' => Config::SECURE_VERIFY_CA],
         'verify-full' => ['mode' => Config::SECURE_VERIFY_FULL],
         'prefer + verify' => ['mode' => Config::SECURE_PREFER, 'verify' => true],
      ] as $label => $secure) {
         $refused = $handshake($secure);

         yield assert(
            assertion: $refused['encrypted'] === null && str_contains($refused['error'], 'certificate verify failed'),
            description: "{$label} refuses the self-signed certificate with the OpenSSL diagnostic and never downgrades — " . json_encode($refused)
         );
      }
   }
);
