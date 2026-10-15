<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Connections;
use Bootgly\WPI\Endpoints\Servers\Decoder\States;
use Bootgly\WPI\Endpoints\Servers\Encoder as ServerEncoder;
use Bootgly\WPI\Endpoints\Servers\Packages as ServerPackages;
use Bootgly\WPI\Events\Select;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Buffers;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections as TCPConnections;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCPPackages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_Holding;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;


/**
 * H-HSC-6 — the guard decoder a parked HTTP/1.1 deferral installs
 * (`Decoder_Holding`) holds what the client pipelines behind it, and no more
 * than one transport read of it (`TCP_Server_CLI\Packages::READ`, 64 KiB).
 *
 * - `decode()` consumes nothing: at `READ` bytes it answers `Incomplete` with
 *   `consumed` 0 and leaves the connection open; at `READ + 1` it closes the
 *   connection (which cancels the park) and answers `Rejected`;
 * - through the transport (`reading()` on a real connection over a socket
 *   pair): a pipelined request and then filler up to exactly `READ` bytes are
 *   kept, undecoded and unanswered, in the receive carry, charged at their
 *   footprint (`Buffers::weigh()`); one byte more closes the connection,
 *   releases the carry and returns the worker ledger to where it was —
 *   nothing was decoded or written at any point;
 * - a carry that adopts one fresh read of half a read (`READ / 2`) or more
 *   keeps that read's whole `READ`-byte allocation (PHP keeps it for any read
 *   of half of it or more): held behind the park it is charged at
 *   `Buffers::weigh(READ)` — at least the footprint measured around a fresh
 *   read of the same size — both when it is retained (`retain()`) and when
 *   the ledger is measured again while it is held (`measure()`: the parked
 *   answer backs up, then drains); one byte less (`READ / 2 - 1`) is charged
 *   at its length. Released, the held requests are decoded and answered and
 *   the ledger returns to where it was; closed, it stays there.
 */
if (! class_exists('U163Encoder', false)) {
   class U163Encoder implements ServerEncoder
   {
      // * Metadata
      public static int $responses = 0;

      public static function encode (ServerPackages $Package, null|int &$length): string
      {
         $count = ++self::$responses;
         $body = "R{$count}";
         $length = strlen($body);

         return $body;
      }
   }
}


return new Test(
   description: 'H-HSC-6: a holding decoder keeps one transport read behind a parked deferral and closes past it',
   test: new Assertions(Case: function (): Generator {
      $limit = TCPPackages::READ;

      // ! Statics this case writes — directly, or through the decoder
      $OldEvent = isset(TCP_Server_CLI::$Event) ? TCP_Server_CLI::$Event : null;
      $OldDecoder = TCP_Server_CLI::$Decoder;
      $OldEncoder = TCP_Server_CLI::$Encoder;
      $oldContext = isset(TCP_Server_CLI::$context) ? TCP_Server_CLI::$context : null;
      $oldIdle = TCP_Server_CLI::$connectionIdleTimeout;
      $OldRequest = isset(HTTP_Server_CLI::$Request) ? HTTP_Server_CLI::$Request : null;

      $Sockets = [];
      $Select = null;

      // ! A server end that reads what the client end sent — everything, up
      //   to one transport read — and a client end that never blocks
      $Open = static function () use (&$Sockets): array {
         $Pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         if ($Pair === false) {
            throw new RuntimeException('U163 could not open a socket pair.');
         }
         [$Server, $Client] = $Pair;
         stream_set_blocking($Server, false);
         stream_set_blocking($Client, false);
         stream_set_read_buffer($Server, 0);
         $Sockets[] = $Server;
         $Sockets[] = $Client;

         return [$Server, $Client];
      };
      // ! Whatever reached the client end, without blocking
      $Receive = static function (mixed $Client): string {
         $wire = '';
         while (is_resource($Client)) {
            $chunk = @fread($Client, 65_536);
            if ($chunk === false || $chunk === '') {
               break;
            }
            $wire .= $chunk;
         }

         return $wire;
      };
      // ! Eight pipelined requests of exactly `$size` bytes in all: the
      //   last head's padding takes the remainder
      $Pipeline = static function (int $size): string {
         $bare = strlen("GET /u163 HTTP/1.1\r\nHost: localhost\r\nX-Pad: \r\n\r\n");
         $pad = str_repeat('p', intdiv($size - 8 * $bare, 8));
         $requests = str_repeat("GET /u163 HTTP/1.1\r\nHost: localhost\r\nX-Pad: {$pad}\r\n\r\n", 7);
         $last = str_repeat('p', $size - strlen($requests) - $bare);

         return "{$requests}GET /u163 HTTP/1.1\r\nHost: localhost\r\nX-Pad: {$last}\r\n\r\n";
      };

      try {
         // ! The worker reactor (teardown deletes from it) and plain-TCP
         //   connections without an idle timer
         $Peers = new class implements Connections {
            public function connect (): bool
            {
               return false;
            }
         };
         $Select = new Select($Peers);
         TCP_Server_CLI::$Event = $Select;
         TCP_Server_CLI::$context = [];
         TCP_Server_CLI::$connectionIdleTimeout = 0;
         TCP_Server_CLI::$Decoder = new Decoder_;
         TCP_Server_CLI::$Encoder = new U163Encoder;
         U163Encoder::$responses = 0;

         // @@ A) Exactly one transport read: held, nothing consumed, open
         [$Socket] = $Open();
         $Direct = new Connection($Socket, '127.0.0.1', 40001);
         $Hold = new Decoder_Holding;
         $Direct->consumed = 7;
         $state = $Hold->decode($Direct, str_repeat('h', $limit), $limit);
         $held = [
            'state' => $state->name,
            'consumed' => $Direct->consumed,
            'status' => $Direct->status,
         ];
         $evidence = json_encode($held);

         yield assert(
            assertion: $held === [
               'state' => States::Incomplete->name,
               'consumed' => 0,
               'status' => TCPConnections::STATUS_ESTABLISHED,
            ],
            description: "At READ bytes the holding decoder answers Incomplete, consumes nothing and keeps the connection open: {$evidence}",
         );

         // @@ B) One byte more: the connection closes, the decode is rejected
         $Direct->consumed = 7;
         $state = $Hold->decode($Direct, str_repeat('h', $limit + 1), $limit + 1);
         $over = [
            'state' => $state->name,
            'consumed' => $Direct->consumed,
            'status' => $Direct->status,
         ];
         $evidence = json_encode($over);

         yield assert(
            assertion: $over === [
               'state' => States::Rejected->name,
               'consumed' => 0,
               'status' => TCPConnections::STATUS_CLOSED,
            ],
            description: "At READ + 1 bytes the holding decoder closes the connection and answers Rejected: {$evidence}",
         );

         // @@ C) Through the transport: what is pipelined behind the park is
         //    kept in the carry, charged, up to one read
         [$Socket, $Client] = $Open();
         $Connection = new Connection($Socket, '127.0.0.1', 40002);
         $Connection->Decoder = new Decoder_Holding;
         $baseline = TCP_Server_CLI::$pendingBytes;

         $pipelined = "GET /u163 HTTP/1.1\r\nHost: localhost\r\n\r\n";
         fwrite($Client, $pipelined);
         $Connection->reading($Socket);
         $kept = [
            'carry' => $Connection->carry === $pipelined,
            'retained' => $Connection->Buffers->retained,
            'pending' => TCP_Server_CLI::$pendingBytes - $baseline,
            'status' => $Connection->status,
            'written' => $Receive($Client),
            'responses' => U163Encoder::$responses,
         ];

         $filler = str_repeat('f', $limit - strlen($pipelined));
         $sent = fwrite($Client, $filler);
         $Connection->reading($Socket);
         $full = [
            'sent' => $sent,
            'carry' => strlen($Connection->carry),
            'retained' => $Connection->Buffers->retained,
            'pending' => TCP_Server_CLI::$pendingBytes - $baseline,
            'status' => $Connection->status,
            'held' => $Connection->Decoder instanceof Decoder_Holding,
            'written' => $Receive($Client),
            'responses' => U163Encoder::$responses,
         ];
         $weighed = Buffers::weigh(strlen($pipelined));
         $evidence = json_encode(['request' => $kept, 'read' => $full]);

         yield assert(
            assertion: $kept === [
               'carry' => true,
               'retained' => $weighed,
               'pending' => $weighed,
               'status' => TCPConnections::STATUS_ESTABLISHED,
               'written' => '',
               'responses' => 0,
            ]
               && $full === [
                  'sent' => strlen($filler),
                  'carry' => $limit,
                  'retained' => Buffers::weigh($limit),
                  'pending' => Buffers::weigh($limit),
                  'status' => TCPConnections::STATUS_ESTABLISHED,
                  'held' => true,
                  'written' => '',
                  'responses' => 0,
               ],
            description: "Through the transport, a pipelined request and filler up to READ bytes are kept undecoded in the carry, charged, the connection open: {$evidence}",
         );

         // @ One byte more than one read
         fwrite($Client, 'f');
         $Connection->reading($Socket);
         $closed = [
            'status' => $Connection->status,
            'carry' => $Connection->carry,
            'retained' => $Connection->Buffers->retained,
            'pending' => TCP_Server_CLI::$pendingBytes - $baseline,
            'written' => $Receive($Client),
            'responses' => U163Encoder::$responses,
         ];
         $evidence = json_encode($closed);

         yield assert(
            assertion: $closed === [
               'status' => TCPConnections::STATUS_CLOSED,
               'carry' => '',
               'retained' => 0,
               'pending' => 0,
               'written' => '',
               'responses' => 0,
            ],
            description: "One byte past READ held closes the connection, releases the carry and returns the ledger, with nothing decoded or written: {$evidence}",
         );

         // @@ D) Half a read held: the carry adopts that read whole and keeps
         //    its `READ`-byte allocation — charged at it; one byte less is
         //    charged at its length
         $half = intdiv($limit, 2);
         $answer = str_repeat('a', 1_000_000);
         $rows = [];
         foreach ([$half - 1, $half] as $size) {
            // ! The real footprint of one fresh read of `$size` bytes
            [$Probe, $Feeder] = $Open();
            fwrite($Feeder, 'w');
            fread($Probe, $limit);
            fwrite($Feeder, str_repeat('p', $size));
            gc_collect_cycles();
            $before = memory_get_usage();
            $fresh = fread($Probe, $limit);
            $footprint = memory_get_usage() - $before;
            $read = strlen((string) $fresh);
            unset($fresh);

            // @ Held behind the park: one read of `$size` bytes
            [$Socket, $Client] = $Open();
            $Connection = new Connection($Socket, '127.0.0.1', 40005);
            $Connection->Decoder = new Decoder_Holding;
            $baseline = TCP_Server_CLI::$pendingBytes;
            $pipeline = $Pipeline($size);
            fwrite($Client, $pipeline);
            $Connection->reading($Socket);
            $held = [
               'carry' => $Connection->carry === $pipeline,
               'retained' => $Connection->Buffers->retained,
               'pending' => TCP_Server_CLI::$pendingBytes - $baseline,
               'covered' => $footprint <= $Connection->Buffers->retained,
            ];

            // @ The parked answer backs up while the carry is held — the
            //   ledger is measured again — then drains
            $Connection->writing($Socket, null, $answer);
            $backlog = [
               'pending' => $Connection->pendingBuffer !== '',
               'carried' => $Connection->Buffers->retained - Buffers::weigh(strlen($Connection->pendingBuffer)),
            ];
            $turns = 0;
            $drained = strlen($Receive($Client));
            // @@ Each write-ready turn flushes what the client made room for
            while ($Connection->pendingBuffer !== '' && $turns++ < 1_000) {
               $Connection->writing($Socket);
               $drained += strlen($Receive($Client));
            }
            $flushed = [
               'answer' => $drained === strlen($answer),
               'retained' => $Connection->Buffers->retained,
            ];

            // @ Released: the held requests decode on a read that brings
            //   nothing, and the ledger returns to where it was
            U163Encoder::$responses = 0;
            $Connection->Decoder = null;
            $Connection->reading($Socket);
            $released = [
               'written' => $Receive($Client),
               'carry' => $Connection->carry,
               'retained' => $Connection->Buffers->retained,
               'pending' => TCP_Server_CLI::$pendingBytes - $baseline,
               'status' => $Connection->status,
            ];
            $Connection->close();

            $rows[$size] = [
               'footprint' => $footprint,
               'read' => $read,
               'held' => $held,
               'backlog' => $backlog,
               'flushed' => $flushed,
               'released' => $released,
               'closed' => TCP_Server_CLI::$pendingBytes - $baseline,
            ];
         }
         $evidence = json_encode($rows);
         $charges = [
            $half - 1 => Buffers::weigh($half - 1),
            $half => Buffers::weigh($limit),
         ];

         // @ Retained: charged at the adopted read's allocation
         $retained = true;
         foreach ($charges as $size => $charge) {
            $retained = $retained
               && $rows[$size]['read'] === $size
               && $rows[$size]['held'] === [
                  'carry' => true,
                  'retained' => $charge,
                  'pending' => $charge,
                  'covered' => true,
               ];
         }

         yield assert(
            assertion: $retained,
            description: "A held carry of half a read is charged at the whole read it adopted (weigh(READ)), at least its measured footprint; one byte less at its length: {$evidence}",
         );

         // @ Measured again while held: the same charge beside the backlog
         $measured = true;
         foreach ($charges as $size => $charge) {
            $measured = $measured
               && $rows[$size]['backlog'] === ['pending' => true, 'carried' => $charge]
               && $rows[$size]['flushed'] === ['answer' => true, 'retained' => $charge];
         }

         yield assert(
            assertion: $measured,
            description: "While the carry is held, the ledger measured again for a backed-up answer — and after it drains — still charges the carry at the read it adopted: {$evidence}",
         );

         // @ Released, then closed: the ledger returns to where it was
         $returned = true;
         foreach ($charges as $size => $charge) {
            $returned = $returned
               && $rows[$size]['released'] === [
                  'written' => 'R1R2R3R4R5R6R7R8',
                  'carry' => '',
                  'retained' => 0,
                  'pending' => 0,
                  'status' => TCPConnections::STATUS_ESTABLISHED,
               ]
               && $rows[$size]['closed'] === 0;
         }

         yield assert(
            assertion: $returned,
            description: "Released, the held requests are decoded and answered and the ledger returns to where it was; closed, it stays there: {$evidence}",
         );
      }
      finally {
         if ($Select !== null) {
            try {
               $Select->destroy();
            }
            catch (Throwable) {
               // Teardown only.
            }
         }
         foreach ($Sockets as $Socket) {
            if (is_resource($Socket)) {
               fclose($Socket);
            }
         }

         TCP_Server_CLI::$Decoder = $OldDecoder;
         TCP_Server_CLI::$Encoder = $OldEncoder;
         TCP_Server_CLI::$connectionIdleTimeout = $oldIdle;
         // ! A typed static no earlier case set cannot return to unset: it
         //   is left inert, as a worker boots with it — the destroyed (empty)
         //   reactor, an empty context and a fresh Request
         if ($oldContext !== null) {
            TCP_Server_CLI::$context = $oldContext;
         }
         if ($OldEvent !== null) {
            TCP_Server_CLI::$Event = $OldEvent;
         }
         HTTP_Server_CLI::$Request = $OldRequest ?? new Request;
      }
   })
);
