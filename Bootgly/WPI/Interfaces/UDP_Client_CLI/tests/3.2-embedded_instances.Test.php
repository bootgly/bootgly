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


use const INF;
use const NAN;
use const STREAM_SERVER_BIND;
use function assert;
use function explode;
use function fclose;
use function getrusage;
use function hrtime;
use function is_resource;
use function json_encode;
use function stream_select;
use function stream_socket_get_name;
use function stream_socket_recvfrom;
use function stream_socket_sendto;
use function stream_socket_server;
use InvalidArgumentException;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Interfaces\UDP_Client_CLI;
use Bootgly\WPI\Interfaces\UDP_Client_CLI\Connections\Connection;


/**
 * Two embedded clients in one process, each with its own reactor, hooks,
 * registry and counters: datagrams the peer interleaves between them reach
 * only their own flow, in order. The datagram wait of a client that owns its
 * reactor is bounded (an expired wait pays its timeout, a zero timeout probes
 * once), takes a zero-byte datagram as a datagram, returns at once when the
 * peer refuses the flow, and refuses a bound it could never keep.
 */
return new Test(
   description: 'UDP_Client_CLI: embedded clients keep their own state and bound their datagram waits',
   test: function () {
      $Peer = stream_socket_server('udp://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND);
      $Closed = stream_socket_server('udp://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND);
      $Clients = [];

      try {
         yield assert(
            assertion: is_resource($Peer) && is_resource($Closed),
            description: 'the loopback peers are bound'
         );
         if (is_resource($Peer) === false || is_resource($Closed) === false) {
            return;
         }
         [$host, $port] = explode(':', (string) stream_socket_get_name($Peer, false));

         // ! Two embedded clients with their own hooks
         $heard = ['A' => [], 'B' => []];
         $Flows = [];
         $Sockets = [];
         foreach (['A', 'B'] as $name) {
            $Client = new UDP_Client_CLI(UDP_Client_CLI::MODE_EMBEDDED);
            $Client->configure(new Configs(host: $host, port: (int) $port));
            $Client->on(Events::DatagramRead, static function ($Socket, Connection $Flow) use ($name, &$heard): void {
               $heard[$name][] = $Flow->input;
            });
            $Socket = $Client->connect();
            $Clients[$name] = $Client;
            $Sockets[$name] = $Socket;
            $Flows[$name] = is_resource($Socket) ? ($Client->Connections->Flows[(int) $Socket] ?? null) : null;
         }
         $FlowA = $Flows['A'];
         $FlowB = $Flows['B'];
         yield assert(
            assertion: $FlowA instanceof Connection && $FlowB instanceof Connection,
            description: 'each embedded client dials its own flow'
         );
         if ($FlowA === null || $FlowB === null) {
            return;
         }
         yield assert(
            assertion: $Clients['A']->Reactor !== $Clients['B']->Reactor
               && $Clients['A']->Connections !== $Clients['B']->Connections
               && $Clients['A']->Callbacks['datagramRead'] !== $Clients['B']->Callbacks['datagramRead']
               && $FlowA->Reactor === $Clients['A']->Reactor
               && $FlowB->Reactor === $Clients['B']->Reactor
               && $FlowA->port === (int) $port
               && stream_socket_get_name($Sockets['A'], false) !== stream_socket_get_name($Sockets['B'], false),
            description: 'reactors, registries and hooks are per instance; each flow has its own source port'
         );

         // @ Each flow says hello; the peer learns both source addresses
         $Addresses = [];
         foreach (['A' => $FlowA, 'B' => $FlowB] as $name => $Flow) {
            $Flow->output = "hello {$name}";
            $Flow->writing($Flow->Socket);
            $read = [$Peer];
            $write = null;
            $except = null;
            stream_select($read, $write, $except, 1);
            $datagram = stream_socket_recvfrom($Peer, 100, 0, $address);
            $Addresses[$name] = $address;
            yield assert(
               assertion: $datagram === "hello {$name}",
               description: "flow {$name} delivers its own datagram"
            );
         }

         // @ The peer interleaves its answers (and a zero-byte datagram)
         foreach ([['B', 'B-1'], ['A', 'A-1'], ['B', 'B-2'], ['A', ''], ['A', 'A-2']] as [$name, $answer]) {
            stream_socket_sendto($Peer, $answer, 0, $Addresses[$name]);
         }
         $got = [];
         foreach (['A', 'B', 'A', 'B', 'A'] as $name) {
            $Flow = $Flows[$name];
            $Outcome = $Flow->receive(1.0);
            $got[] = [$name, $Outcome->name, $Flow->input];
         }
         yield assert(
            assertion: $got === [
               ['A', 'Received', 'A-1'], ['B', 'Received', 'B-1'], ['A', 'Received', ''],
               ['B', 'Received', 'B-2'], ['A', 'Received', 'A-2'],
            ],
            description: 'interleaved datagrams reach only their own flow, in order — a zero-byte one included: '
               . json_encode($got)
         );
         yield assert(
            assertion: $heard === ['A' => ['A-1', '', 'A-2'], 'B' => ['B-1', 'B-2']]
               && [$FlowA->writes, $FlowA->reads, $FlowB->writes, $FlowB->reads] === [1, 3, 1, 2],
            description: 'each client hears only its own datagrams and counts only its own flow: '
               . json_encode($heard)
         );

         // @ An expired wait pays its bound, sleeping; a zero bound probes once
         $Usage = getrusage();
         $baseline = $Usage['ru_utime.tv_sec'] + $Usage['ru_utime.tv_usec'] / 1e6
            + $Usage['ru_stime.tv_sec'] + $Usage['ru_stime.tv_usec'] / 1e6;
         $started = hrtime(true);
         $Expired = $FlowA->receive(0.2);
         $waited = (hrtime(true) - $started) / 1e9;
         $Usage = getrusage();
         $spent = $Usage['ru_utime.tv_sec'] + $Usage['ru_utime.tv_usec'] / 1e6
            + $Usage['ru_stime.tv_sec'] + $Usage['ru_stime.tv_usec'] / 1e6 - $baseline;
         $started = hrtime(true);
         $Probed = $FlowA->receive(0.0);
         $probed = (hrtime(true) - $started) / 1e9;
         yield assert(
            assertion: $Expired === Outcomes::Expired && $waited >= 0.19 && $waited < 0.6 && $spent < 0.05
               && $Probed === Outcomes::Expired && $probed < 0.05,
            description: "a silent peer expires the wait at its bound ({$waited}s, {$spent}s of CPU) and a zero bound only probes ({$probed}s)"
         );

         // @ A bound the wait could never keep is refused
         $refused = [];
         foreach ([-0.5, INF, NAN, 1e10] as $timeout) {
            try {
               $FlowA->receive($timeout);
            }
            catch (InvalidArgumentException) {
               $refused[] = true;
            }
         }
         yield assert(
            assertion: $refused === [true, true, true, true],
            description: 'a negative, non-finite or clock-overflowing timeout is refused'
         );

         // @ A peer that refuses the flow (closed port) ends the wait at once
         [$closedHost, $closedPort] = explode(':', (string) stream_socket_get_name($Closed, false));
         fclose($Closed);
         $Refused = new UDP_Client_CLI(UDP_Client_CLI::MODE_EMBEDDED);
         $Refused->configure(new Configs(host: $closedHost, port: (int) $closedPort));
         $Clients['C'] = $Refused;
         $Socket = $Refused->connect();
         $FlowC = is_resource($Socket) ? ($Refused->Connections->Flows[(int) $Socket] ?? null) : null;
         $Outcome = null;
         $refusal = 99.0;
         if ($FlowC !== null) {
            $FlowC->output = 'anyone?';
            $FlowC->writing($Socket);
            $started = hrtime(true);
            $Outcome = $FlowC->receive(2.0);
            $refusal = (hrtime(true) - $started) / 1e9;
         }
         yield assert(
            assertion: $Outcome === Outcomes::Refused && $refusal < 1.0 && $FlowC?->errors['read'] === 1,
            description: "a refused flow ends its wait at once ({$refusal}s), counted as a read error on the flow"
         );
      }
      finally {
         // @ Cleanup
         foreach ($Clients as $Client) {
            $Client->stop();
         }
         if (is_resource($Peer)) {
            fclose($Peer);
         }
         if (is_resource($Closed)) {
            fclose($Closed);
         }
      }
   }
);
