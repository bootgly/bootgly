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
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Modules\WS;
use Bootgly\WPI\Nodes\WS_Client_CLI\Message\Frame as ClientFrame;
use Bootgly\WPI\Nodes\WS_Server_CLI\Decoders\Decoder_Framing;
use Bootgly\WPI\Nodes\WS_Server_CLI\Message\Frame as ServerFrame;
use Bootgly\WPI\Nodes\WS_Server_CLI\Session;


/**
 * M4 (fairness) — inbound WebSocket holds use at most half of the worker
 * ledger, so pending output always keeps room, and a hold that does not fit
 * closes the session holding the most inbound bytes (1009) when it holds
 * more than the asking session would — an attacker parking big unfinished
 * messages cannot deny a small frame split across reads. Holds are charged
 * at `Buffers::weigh()`.
 */
return new Test(
   description: 'M4: inbound holds keep to half the worker ledger and evict the largest holder before a smaller one',
   test: function () {
      $Evidence = [
         'error' => '',
         'weigh' => null,
         'evicted' => null,
         'refused' => null,
         'output' => null,
         'largest' => null,
         'equal' => null,
         'oversize' => null,
         'orphan' => null,
         'drained' => null,
      ];
      $Pairs = [];
      /** @var array<int,Session> $Sessions */
      $Sessions = [];
      $previousIdle = TCP_Server_CLI::$connectionIdleTimeout;
      $previousCap = TCP_Server_CLI::$maxWorkerPendingBytes;
      $previousMessage = Session::$maxMessageSize;
      $base = TCP_Server_CLI::$pendingBytes;

      try {
         // ? Every cap below is exact: a reservation an earlier test leaked
         //   would skew it, so the spec says so instead of guessing
         if ($base !== 0) {
            throw new RuntimeException("the worker ledger is not empty at start ({$base} bytes): an earlier test leaked a reservation");
         }
         // @ The allocator footprint of a held string
         $Evidence['weigh'] = [
            Buffers::weigh(0),
            Buffers::weigh(100),
            Buffers::weigh(1_000),
            Buffers::weigh(102_414),
            Buffers::weigh(1_045_000),
            Buffers::weigh(1_048_576),
            Buffers::weigh(8_388_608),
         ];

         TCP_Server_CLI::$connectionIdleTimeout = 0;
         $Decoder = new Decoder_Framing;

         /** A registered session whose transport close needs no event loop. */
         $Open = static function () use (&$Pairs, &$Sessions): array {
            $Pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            if ($Pair === false) {
               throw new RuntimeException('could not create a socket pair');
            }
            stream_set_blocking($Pair[1], false);
            $Pairs[] = $Pair;
            $Connection = new class($Pair[0], '127.0.0.1', 44000 + count($Pairs)) extends Connection {
               // ! No event loop in this process: close() only marks the status
               public function close (): true
               {
                  $this->status = Connections::STATUS_CLOSED;

                  return true;
               }
            };
            $Connection->status = Connections::STATUS_ESTABLISHED;
            $Session = new Session($Connection);
            $Connection->decoded = $Session;
            Connections::$Connections[$Connection->id] = $Connection;
            $Sessions[] = $Session;

            return [$Connection, $Session, $Pair[1]];
         };
         $Feed = static function (Connection $Connection, string $wire) use ($Decoder): string {
            $Connection->consumed = 0;

            return $Decoder->decode($Connection, $wire, strlen($wire))->name;
         };
         /** A partial frame of `$bytes` payload bytes (a 1 MB frame announced). */
         $Partial = static fn (int $bytes, string $fill = 'P'): string
            => substr(ClientFrame::encode(WS::OPCODE_BINARY, str_repeat($fill, 1_000_000)), 0, $bytes + 14);
         /** The close code in the bytes a peer received (or a session queued), if any. */
         $Code = static function (string $bytes): null|int {
            $Close = ServerFrame::decode($bytes, 0, 1024);
            if ($Close === null || $Close->opcode !== WS::OPCODE_CLOSE || strlen($Close->payload) < 2) {
               return null;
            }
            $unpacked = unpack('n', substr($Close->payload, 0, 2));

            return is_array($unpacked) ? (int) $unpacked[1] : null;
         };
         /** Close every session opened so far (each returns its hold). */
         $Reset = static function () use (&$Sessions): void {
            foreach ($Sessions as $Session) {
               $Session->disconnect();
            }
         };
         /** The cap whose inbound half is exactly `$share`. */
         $Share = static function (int $share): void {
            TCP_Server_CLI::$maxWorkerPendingBytes = 2 * $share;
         };

         // # A small split frame evicts the big holder
         $big = 600_000;
         $bigHold = Buffers::weigh($big + 14);
         $Share($bigHold);
         [$BigConnection, $Big, $BigPeer] = $Open();
         $Feed($BigConnection, $Partial($big, 'B'));
         [$SmallConnection, $Small] = $Open();
         $small = ClientFrame::encode(WS::OPCODE_TEXT, str_repeat('s', 200));
         $state = $Feed($SmallConnection, substr($small, 0, 100));
         $Evidence['evicted'] = [
            $state,
            $Big->disconnected,
            $Code((string) @fread($BigPeer, 65_536)),
            $Small->disconnected,
            TCP_Server_CLI::$pendingBytes - $base === Buffers::weigh(100),
         ];
         $state = $Feed($SmallConnection, substr($small, 100));
         $Evidence['evicted'][] = [$state, strlen((string) $Small->Message?->payload), $Small->Buffers->retained];

         // # A requester bigger than every holder is refused itself
         [$HolderConnection, $Holder] = $Open();
         $Feed($HolderConnection, $Partial(50_000, 'H'));
         [$GreedyConnection, $Greedy] = $Open();
         $state = $Feed($GreedyConnection, $Partial($big, 'G'));
         $Evidence['refused'] = [$state, $Greedy->disconnected, $Code($Greedy->outbox), $Holder->disconnected];
         $Reset();

         // # With the inbound share full, pending output still has its half
         [$FillConnection, $Fill] = $Open();
         $Feed($FillConnection, $Partial($big, 'F'));
         $Output = new Buffers;
         $Evidence['output'] = [
            $Fill->disconnected,
            TCP_Server_CLI::$pendingBytes - $base <= $bigHold,
            $Output->reserve($bigHold),
         ];
         $Output->release();
         $Reset();

         // # Only the LARGEST holder is evicted
         $Share(Buffers::weigh(300_014) + Buffers::weigh(600_014));
         [$MiddleConnection, $Middle] = $Open();
         $Feed($MiddleConnection, $Partial(300_000, 'M'));
         [$LargeConnection, $Large] = $Open();
         $Feed($LargeConnection, $Partial(600_000, 'L'));
         [$AskerConnection, $Asker] = $Open();
         $state = $Feed($AskerConnection, $Partial(100_000, 'A'));
         $Evidence['largest'] = [$state, $Large->disconnected, $Middle->disconnected, $Asker->disconnected];
         $Reset();

         // # A holder no bigger than the asking session would be is never evicted
         $Share(Buffers::weigh(300_014));
         [$PeerConnection, $Peer] = $Open();
         $Feed($PeerConnection, $Partial(300_000, 'Q'));
         [$TwinConnection, $Twin] = $Open();
         $state = $Feed($TwinConnection, $Partial(300_000, 'T'));
         $Evidence['equal'] = [$state, $Peer->disconnected, $Twin->disconnected, $Code($Twin->outbox)];
         $Reset();

         // # A fragment past maxMessageSize fails on size, before it evicts anyone
         $Share(Buffers::weigh(999_014) + Buffers::weigh(300_000));
         [$WholeConnection, $Whole] = $Open();
         $Feed($WholeConnection, $Partial(999_000, 'W'));
         Session::$maxMessageSize = 400_000;
         [$OverConnection, $Over] = $Open();
         $Feed($OverConnection, ClientFrame::encode(WS::OPCODE_BINARY, str_repeat('o', 300_000), fin: false));
         $state = $Feed($OverConnection, ClientFrame::encode(WS::OPCODE_CONTINUATION, str_repeat('o', 200_000), fin: false));
         $Evidence['oversize'] = [$state, $Over->disconnected, $Code($Over->outbox), $Whole->disconnected];
         Session::$maxMessageSize = $previousMessage;
         $Reset();

         // # A session freed without disconnect() returns its share
         $Share(Buffers::weigh(300_014));
         [$OrphanConnection, $Orphan] = $Open();
         $Feed($OrphanConnection, $Partial(300_000, 'O'));
         unset(Connections::$Connections[$OrphanConnection->id]);
         $Sessions = array_values(array_filter($Sessions, static fn (Session $Session): bool => $Session !== $Orphan));
         $OrphanConnection->decoded = null;
         unset($Orphan, $OrphanConnection);
         gc_collect_cycles();
         [$HeirConnection, $Heir] = $Open();
         $state = $Feed($HeirConnection, $Partial(300_000, 'I'));
         $Evidence['orphan'] = [$state, $Heir->disconnected];
         $Reset();

         $Evidence['drained'] = TCP_Server_CLI::$pendingBytes - $base;
      }
      catch (Throwable $Throwable) {
         $Evidence['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         foreach ($Sessions as $Session) {
            $Session->disconnect();
            unset(Connections::$Connections[$Session->Connection->id]);
         }
         Session::$maxMessageSize = $previousMessage;
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
            // ! 102,414 B (26 pages) pays a 1/18 chunk share: 19 fit, one is lost to fragmentation
            && $Evidence['weigh'] === [0, 128, 1_280, 116_509, 2_097_152, 2_097_152, 8_392_704],
         description: 'M4 a held string must be weighed at its allocator footprint: ' . json_encode($Evidence)
      );
      yield assert(
         assertion: $Evidence['evicted'] === ['Incomplete', true, 1009, false, true, ['Complete', 200, 0]]
            && $Evidence['refused'] === ['Complete', true, 1009, false],
         description: 'M4 a small split frame must evict a bigger holder (1009); a requester bigger than every holder is refused itself: '
            . json_encode($Evidence)
      );
      yield assert(
         assertion: $Evidence['largest'] === ['Incomplete', true, false, false]
            && $Evidence['equal'] === ['Complete', false, true, 1009],
         description: 'M4 only the largest holder is evicted, and only while it holds more than the asking session would: '
            . json_encode($Evidence)
      );
      yield assert(
         assertion: $Evidence['oversize'] === ['Complete', true, 1009, false]
            && $Evidence['orphan'] === ['Incomplete', false],
         description: 'M4 an oversize fragment evicts nobody; a session freed without disconnect returns its share: '
            . json_encode($Evidence)
      );
      yield assert(
         assertion: $Evidence['output'] === [false, true, true] && $Evidence['drained'] === 0,
         description: 'M4 pending output must keep its half of the ledger while inbound holds are full: '
            . json_encode($Evidence)
      );
   }
);
