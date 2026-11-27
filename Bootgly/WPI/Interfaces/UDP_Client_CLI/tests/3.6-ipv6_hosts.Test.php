<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\UDP_Client_CLI;


use const STREAM_SERVER_BIND;
use function assert;
use function fclose;
use function is_resource;
use function json_encode;
use function str_contains;
use function str_repeat;
use function stream_select;
use function stream_socket_get_name;
use function stream_socket_recvfrom;
use function stream_socket_server;
use function strrpos;
use function substr;
use InvalidArgumentException;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Interfaces\UDP_Client_CLI;


/**
 * An IPv6 host is configured bare or bracketed, with an optional zone id that
 * names the interface of a link-local address, and is dialed bracketed. A host
 * that can only be an IPv6 literal but is not one as a whole — a port after
 * the brackets, unbalanced brackets, a zone id with other characters or an
 * empty one — is refused by name at configuration, so a host string never
 * overrides the configured port.
 */
return new Test(
   description: 'UDP_Client_CLI: IPv6 literal hosts (with zone ids) are dialed bracketed; anything else is refused by name',
   test: function () {
      // @ Accepted at configuration
      $accepted = [];
      foreach (
         ['::1', '[::1]', 'fe80::1%eth0', '[fe80::1%lo0]', 'fe80::1%en0.100', '::ffff:127.0.0.1', '2001:db8::53', '127.0.0.1', 'localhost']
         as $host
      ) {
         try {
            new Configs(host: $host, port: 53);
            $accepted[] = $host;
         }
         catch (InvalidArgumentException) {
            // ...
         }
      }
      yield assert(
         assertion: $accepted === [
            '::1', '[::1]', 'fe80::1%eth0', '[fe80::1%lo0]', 'fe80::1%en0.100', '::ffff:127.0.0.1', '2001:db8::53',
            '127.0.0.1', 'localhost',
         ],
         description: 'IPv6 literals bare or bracketed, with a zone id, and every host without :, [, ] or % are accepted: '
            . json_encode($accepted)
      );

      // @ Refused at configuration, by name
      $refused = [];
      foreach (
         [
            '[::1]:9999', '::1]:9999', '[::1', 'fe80::1%eth0/x', 'fe80::1%', 'fe80::1%' . str_repeat('a', 65),
            'host%eth0', '127.0.0.1:9999', 'example.test:53', '[127.0.0.1]', 'fe80::1%eth 0', ':::1',
         ] as $host
      ) {
         try {
            new Configs(host: $host, port: 53);
         }
         catch (InvalidArgumentException $Exception) {
            if (str_contains($Exception->getMessage(), 'zone id')) {
               $refused[] = $host;
            }
         }
      }
      yield assert(
         assertion: $refused === [
            '[::1]:9999', '::1]:9999', '[::1', 'fe80::1%eth0/x', 'fe80::1%', 'fe80::1%' . str_repeat('a', 65),
            'host%eth0', '127.0.0.1:9999', 'example.test:53', '[127.0.0.1]', 'fe80::1%eth 0', ':::1',
         ],
         description: 'a host that is not an IPv6 literal as a whole is refused, naming the zone id: ' . json_encode($refused)
      );

      // @ A bare IPv6 literal is dialed bracketed (against an IPv6 loopback peer when the host has one)
      $Peer = @stream_socket_server('udp://[::1]:0', $code, $message, STREAM_SERVER_BIND);
      $Client = new UDP_Client_CLI(UDP_Client_CLI::MODE_EMBEDDED);
      try {
         if (is_resource($Peer)) {
            $name = (string) stream_socket_get_name($Peer, false);
            $port = (int) substr($name, (int) strrpos($name, ':') + 1);
            $Client->configure(new Configs(host: '::1', port: $port));
            $Socket = $Client->connect();
            $Flow = is_resource($Socket) ? ($Client->Connections->Flows[(int) $Socket] ?? null) : null;
            $datagram = null;
            if ($Flow !== null) {
               $Flow->output = 'over IPv6';
               $Flow->writing($Socket);
               $read = [$Peer];
               $write = null;
               $except = null;
               stream_select($read, $write, $except, 1);
               $datagram = stream_socket_recvfrom($Peer, 100);
            }
            yield assert(
               assertion: $datagram === 'over IPv6' && $Flow?->address === '::1' && $Flow->port === $port,
               description: 'a client configured with the bare literal ::1 reaches the IPv6 loopback peer'
            );
         }
         else {
            // ! No IPv6 loopback here: the dial still names the bracketed
            //   literal (an unbracketed one fails resolving an empty host)
            $Client->configure(new Configs(host: '::1', port: 53));
            $Socket = $Client->connect();
            $reason = (string) ($Client->error['message'] ?? '');
            yield assert(
               assertion: $Socket === false && str_contains($reason, 'getaddrinfo for  failed') === false,
               description: "without an IPv6 loopback the bracketed dial fails on the family, never on an empty host: {$reason}"
            );
         }
      }
      finally {
         $Client->stop();
         if (is_resource($Peer)) {
            fclose($Peer);
         }
      }
   }
);
