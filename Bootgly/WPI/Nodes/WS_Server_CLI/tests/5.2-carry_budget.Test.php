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
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Buffers;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Modules\WS;
use Bootgly\WPI\Nodes\WS_Client_CLI\Message\Frame as ClientFrame;
use Bootgly\WPI\Nodes\WS_Server_CLI\Decoders\Decoder_Framing;
use Bootgly\WPI\Nodes\WS_Server_CLI\Message\Frame as ServerFrame;
use Bootgly\WPI\Nodes\WS_Server_CLI\Session;


/**
 * M4 (budget, carry) — a partial frame parked between reads is charged to the
 * worker ledger at its allocator footprint (`Buffers::weigh()`), inside the
 * inbound half of `TCP_Server_CLI::$maxWorkerPendingBytes`; a hold past it
 * closes that session with 1009. Inside an open message the charge always
 * equals what the session holds — a frame split across reads and a control
 * frame completed out of a carry included.
 */
return new Test(
   description: 'M4: partial-frame carry is charged to the worker ledger at its footprint (1009 past the inbound share)',
   test: function () {
      $Evidence = [
         'error' => '',
         'admitted' => null,
         'refused' => null,
         'completed' => null,
         'released' => null,
         'sliced' => null,
         'pinged' => null,
         'drained' => null,
         'closed' => null,
      ];
      $Pairs = [];
      /** @var array<int,Session> $Sessions */
      $Sessions = [];
      $previousIdle = TCP_Server_CLI::$connectionIdleTimeout;
      $previousCap = TCP_Server_CLI::$maxWorkerPendingBytes;
      $base = TCP_Server_CLI::$pendingBytes;

      try {
         // ? Every cap below is exact: a reservation an earlier test leaked
         //   would skew it, so the spec says so instead of guessing
         if ($base !== 0) {
            throw new RuntimeException("the worker ledger is not empty at start ({$base} bytes): an earlier test leaked a reservation");
         }
         TCP_Server_CLI::$connectionIdleTimeout = 0;
         $part = 102400;
         $hold = Buffers::weigh($part + 14);
         // ! The inbound share (half the ledger) fits two partials, not three
         TCP_Server_CLI::$maxWorkerPendingBytes = 2 * (2 * $hold + 1000);
         $Decoder = new Decoder_Framing;

         $Open = static function () use (&$Pairs, &$Sessions): array {
            $Pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            if ($Pair === false) {
               throw new RuntimeException('could not create a socket pair');
            }
            $Pairs[] = $Pair;
            $Connection = new Connection($Pair[0], '127.0.0.1', 41000 + count($Pairs));
            $Session = new Session($Connection);
            $Connection->decoded = $Session;
            $Sessions[] = $Session;

            return [$Connection, $Session];
         };
         $Feed = static function (Connection $Connection, string $wire) use ($Decoder): string {
            $Connection->consumed = 0;

            return $Decoder->decode($Connection, $wire, strlen($wire))->name;
         };
         $Code = static function (Session $Session): null|int {
            $Close = ServerFrame::decode($Session->outbox, 0, 1024);
            if ($Close === null || strlen($Close->payload) < 2) {
               return null;
            }
            $unpacked = unpack('n', substr($Close->payload, 0, 2));

            return is_array($unpacked) ? (int) $unpacked[1] : null;
         };
         /** Whether the session's charge equals the footprint of what it holds. */
         $Exact = static fn (Session $Session): bool => $Session->Buffers->retained
            === Buffers::weigh(strlen($Session->carry)) + Buffers::weigh(strlen($Session->reassembly));

         $frame = ClientFrame::encode(WS::OPCODE_BINARY, str_repeat('C', 122880));
         $head = substr($frame, 0, $part + 14);
         $tail = substr($frame, $part + 14);

         [$AConnection, $A] = $Open();
         [$BConnection, $B] = $Open();
         [$CConnection, $C] = $Open();
         $states = [$Feed($AConnection, $head), $Feed($BConnection, $head)];
         $Evidence['admitted'] = [$states, TCP_Server_CLI::$pendingBytes - $base];

         $state = $Feed($CConnection, $head);
         $Evidence['refused'] = [$state, $Code($C), $C->disconnected, strlen($C->carry), TCP_Server_CLI::$pendingBytes - $base];

         // @ The closed session reads nothing more (its close frame drains)
         $state = $Feed($CConnection, $head);
         $Evidence['closed'] = [$state, $C->carry, $C->Buffers->retained, $C->deadline];

         $state = $Feed($AConnection, $tail);
         $Evidence['completed'] = [$state, strlen((string) $A->Message?->payload), TCP_Server_CLI::$pendingBytes - $base];

         $B->disconnect();
         // @ A control frame completed out of a carry returns it too
         [$DConnection, $D] = $Open();
         $ping = ClientFrame::encode(WS::OPCODE_PING, 'live');
         $Feed($DConnection, substr($ping, 0, 3));
         $Feed($DConnection, substr($ping, 3));
         $Evidence['released'] = [TCP_Server_CLI::$pendingBytes - $base, $B->carry === '', $D->Buffers->retained];

         // @ A continuation split across reads inside an open message
         [$EConnection, $E] = $Open();
         $Feed($EConnection, ClientFrame::encode(WS::OPCODE_BINARY, str_repeat('E', 4096), fin: false));
         $continuation = ClientFrame::encode(WS::OPCODE_CONTINUATION, str_repeat('e', 40000), fin: false);
         $Feed($EConnection, substr($continuation, 0, 16384));
         $first = $Exact($E);
         $Feed($EConnection, substr($continuation, 16384, 16384));
         $Evidence['sliced'] = [$first, $Exact($E), strlen($E->carry), strlen($E->reassembly)];

         // @ A PING split across reads inside the open message
         $Feed($EConnection, substr($continuation, 32768));
         $ping = ClientFrame::encode(WS::OPCODE_PING, 'mid');
         $Feed($EConnection, substr($ping, 0, 3));
         $Feed($EConnection, substr($ping, 3));
         $Evidence['pinged'] = [$Exact($E), $E->carry, strlen($E->reassembly), $E->deadline !== 0];

         // @ Every session closed: the ledger is back where it was
         foreach ($Sessions as $Session) {
            $Session->disconnect();
         }
         $Evidence['drained'] = TCP_Server_CLI::$pendingBytes - $base;
      }
      catch (Throwable $Throwable) {
         $Evidence['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         foreach ($Sessions as $Session) {
            $Session->disconnect();
         }
         TCP_Server_CLI::$connectionIdleTimeout = $previousIdle;
         TCP_Server_CLI::$maxWorkerPendingBytes = $previousCap;
         foreach ($Pairs as $Pair) {
            foreach ($Pair as $Stream) {
               if (is_resource($Stream)) {
                  fclose($Stream);
               }
            }
         }
      }

      yield assert(
         assertion: $Evidence['error'] === ''
            && $Evidence['admitted'] === [['Incomplete', 'Incomplete'], 2 * $hold],
         description: 'M4 two parked partial frames must be charged at their footprint: ' . json_encode($Evidence)
      );
      yield assert(
         assertion: $Evidence['refused'] === ['Complete', 1009, true, 0, 2 * $hold],
         description: 'M4 the partial frame past the inbound share must close 1009 and hold nothing: '
            . json_encode($Evidence)
      );
      yield assert(
         assertion: $Evidence['completed'] === ['Complete', 122880, $hold]
            && $Evidence['released'] === [0, true, 0],
         description: 'M4 completing or disconnecting must return the carry to the ledger: ' . json_encode($Evidence)
      );
      yield assert(
         assertion: $Evidence['sliced'] === [true, true, 32768, 4096]
            && $Evidence['pinged'] === [true, '', 44096, true]
            && $Evidence['drained'] === 0
            && $Evidence['closed'] === ['Incomplete', '', 0, 0],
         description: 'M4 inside an open message the charge must equal what is held, split frames and PINGs included: '
            . json_encode($Evidence)
      );
   }
);
