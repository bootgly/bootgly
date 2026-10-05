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


// ! Embedded fixture: K peers each park bytes at the stock limits inside a
//   64M worker-sized process (the M4 worker fatal), fed in 64 KiB reads as
//   a live socket delivers them: `messages` — 7 whole 1 MiB fragments then
//   one more parked after its first read; `carries` — one 1 MiB frame parked
//   one byte short (a hold that costs a whole 2 MiB allocator chunk).
if (
   realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)
   && ($_SERVER['argv'][1] ?? null) === '--ws-reassembly-flood'
) {
   $root = rtrim((string) ($_SERVER['argv'][2] ?? ''), '/');
   $mode = (string) ($_SERVER['argv'][3] ?? 'messages');
   $_SERVER['SCRIPT_FILENAME'] = '';
   require "{$root}/autoboot.php";

   TCP_Server_CLI::$connectionIdleTimeout = 0;
   $Decoder = new Decoder_Framing;
   $chunk = str_repeat('R', Session::$maxFrameSize);
   /** Feed `$wire` in 64 KiB reads, stopping after `$reads` of them (0: all). */
   $Feed = static function (Connection $Connection, Session $Session, string $wire, int $reads = 0) use ($Decoder): void {
      $length = strlen($wire);
      for ($offset = 0, $read = 0; $offset < $length && $Session->disconnected === false; $offset += 65_536) {
         if ($reads > 0 && $read++ === $reads) {
            return;
         }
         $slice = substr($wire, $offset, 65_536);
         $Connection->consumed = 0;
         $Decoder->decode($Connection, $slice, strlen($slice));
      }
   };
   $Kept = [];
   $refused = 0;
   $peers = $mode === 'carries' ? 60 : 16;
   for ($peer = 1; $peer <= $peers; $peer++) {
      $Pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      $Connection = new Connection($Pair[0], '127.0.0.1', 42000 + $peer);
      $Session = new Session($Connection);
      $Connection->decoded = $Session;
      if ($mode === 'carries') {
         $wire = ClientFrame::encode(WS::OPCODE_BINARY, $chunk);
         $Feed($Connection, $Session, substr($wire, 0, -1));
      }
      else {
         for ($i = 0; $i < 7 && $Session->disconnected === false; $i++) {
            $wire = ClientFrame::encode($i === 0 ? WS::OPCODE_BINARY : WS::OPCODE_CONTINUATION, $chunk, fin: false);
            $Feed($Connection, $Session, $wire);
         }
         $wire = ClientFrame::encode(WS::OPCODE_CONTINUATION, $chunk, fin: false);
         $Feed($Connection, $Session, $wire, reads: 1);
      }
      $refused += $Session->disconnected ? 1 : 0;
      $Kept[] = [$Pair, $Connection, $Session];
   }
   echo json_encode(['refused' => $refused, 'pending' => TCP_Server_CLI::$pendingBytes]);
   exit(0);
}


/**
 * M4 (budget, reassembly) — fragments of an unfinished message are charged to
 * the worker ledger before they are appended, so the sum over every session
 * is bounded by `TCP_Server_CLI::$maxWorkerPendingBytes`, not by
 * `maxConnections` × `maxMessageSize`.
 */
return new Test(
   description: 'M4: open-message reassembly is charged to the worker ledger at its footprint (1009 past the inbound share)',
   skip: function_exists('proc_open') === false,
   test: function () {
      $Evidence = ['error' => '', 'boundary' => null, 'refused' => null, 'finished' => null, 'freed' => null, 'final' => null, 'flood' => null];
      $Pairs = [];
      $previousIdle = TCP_Server_CLI::$connectionIdleTimeout;
      $previousCap = TCP_Server_CLI::$maxWorkerPendingBytes;

      try {
         TCP_Server_CLI::$connectionIdleTimeout = 0;
         $base = TCP_Server_CLI::$pendingBytes;
         // ? Every cap below is exact: a reservation an earlier test leaked
         //   would skew it, so the spec says so instead of guessing
         if ($base !== 0) {
            throw new RuntimeException("the worker ledger is not empty at start ({$base} bytes): an earlier test leaked a reservation");
         }
         $fragment = 262144;
         // ! The inbound share (half the ledger) is exactly 4 fragments' footprint
         $full = Buffers::weigh(4 * $fragment);
         TCP_Server_CLI::$maxWorkerPendingBytes = 2 * $full;
         $Decoder = new Decoder_Framing;

         $Open = static function () use (&$Pairs): array {
            $Pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            if ($Pair === false) {
               throw new RuntimeException('could not create a socket pair');
            }
            $Pairs[] = $Pair;
            $Connection = new Connection($Pair[0], '127.0.0.1', 43000 + count($Pairs));
            $Session = new Session($Connection);
            $Connection->decoded = $Session;

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
         $payload = str_repeat('R', $fragment);

         // @ A fills the budget exactly (boundary admitted).
         [$AConnection, $A] = $Open();
         for ($i = 0; $i < 4; $i++) {
            $Feed($AConnection, ClientFrame::encode($i === 0 ? WS::OPCODE_BINARY : WS::OPCODE_CONTINUATION, $payload, fin: false));
         }
         $Evidence['boundary'] = [$A->disconnected, strlen($A->reassembly), TCP_Server_CLI::$pendingBytes - $base];

         // @ B's first fragment does not fit: 1009, nothing held.
         [$BConnection, $B] = $Open();
         $Feed($BConnection, ClientFrame::encode(WS::OPCODE_BINARY, $payload, fin: false));
         $Evidence['refused'] = [$Code($B), $B->disconnected, strlen($B->reassembly), TCP_Server_CLI::$pendingBytes - $base];

         // @ A finishes: the message surfaces and the ledger is returned.
         $Feed($AConnection, ClientFrame::encode(WS::OPCODE_CONTINUATION, 'end'));
         $Evidence['finished'] = [strlen((string) $A->Message?->payload), TCP_Server_CLI::$pendingBytes - $base];

         // @ D opens a message and disconnects mid-way: released at once.
         [$DConnection, $D] = $Open();
         $Feed($DConnection, ClientFrame::encode(WS::OPCODE_BINARY, $payload, fin: false));
         $held = TCP_Server_CLI::$pendingBytes - $base;
         $D->disconnect();
         $Evidence['freed'] = [$held, TCP_Server_CLI::$pendingBytes - $base, $D->reassembly === ''];

         // @ The final fragment is never held: a share that fits only the open
         //   message still completes it
         TCP_Server_CLI::$maxWorkerPendingBytes = 2 * Buffers::weigh(100_000);
         [$FConnection, $F] = $Open();
         $Feed($FConnection, ClientFrame::encode(WS::OPCODE_BINARY, str_repeat('f', 100_000), fin: false));
         $state = $Feed($FConnection, ClientFrame::encode(WS::OPCODE_CONTINUATION, str_repeat('f', 10_000)));
         $Evidence['final'] = [$state, $F->disconnected, strlen((string) $F->Message?->payload), TCP_Server_CLI::$pendingBytes - $base];
         $F->disconnect();
      }
      catch (Throwable $Throwable) {
         $Evidence['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
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

      // @ The worker fatal itself, at stock limits in a 64M process (the
      //   default 64 MiB ledger: only its inbound half, charged at footprint, fits)
      $Evidence['flood'] = [];
      foreach (['messages', 'carries'] as $mode) {
         $Process = proc_open(
            [PHP_BINARY, '-d', 'memory_limit=64M', '-d', 'opcache.jit=0', __FILE__, '--ws-reassembly-flood', BOOTGLY_ROOT_BASE, $mode],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $Pipes
         );
         if (is_resource($Process) === false) {
            continue;
         }
         $out = (string) stream_get_contents($Pipes[1]);
         $err = (string) stream_get_contents($Pipes[2]);
         fclose($Pipes[1]);
         fclose($Pipes[2]);
         $exit = proc_close($Process);
         $Evidence['flood'][$mode] = [
            'exit' => $exit,
            'refused' => (int) (json_decode($out, true)['refused'] ?? 0),
            'fatal' => str_contains($out . $err, 'Allowed memory size'),
         ];
      }

      yield assert(
         assertion: $Evidence['error'] === '' && $Evidence['boundary'] === [false, 4 * $fragment, $full],
         description: 'M4 open-message fragments must be charged at their footprint up to the exact share: ' . json_encode($Evidence)
      );
      yield assert(
         assertion: $Evidence['refused'] === [1009, true, 0, $full],
         description: 'M4 a fragment past the inbound share must close 1009 and hold nothing: ' . json_encode($Evidence)
      );
      yield assert(
         assertion: $Evidence['finished'] === [4 * $fragment + 3, 0]
            && $Evidence['freed'] === [Buffers::weigh($fragment), 0, true]
            && $Evidence['final'] === ['Complete', false, 110_000, 0],
         description: 'M4 FIN and disconnect must return the reassembly to the ledger: ' . json_encode($Evidence)
      );
      yield assert(
         assertion: $Evidence['flood'] === [
            'messages' => ['exit' => 0, 'refused' => $Evidence['flood']['messages']['refused'] ?? -1, 'fatal' => false],
            'carries' => ['exit' => 0, 'refused' => $Evidence['flood']['carries']['refused'] ?? -1, 'fatal' => false],
         ]
            && $Evidence['flood']['messages']['refused'] >= 8
            && $Evidence['flood']['carries']['refused'] >= 30,
         description: 'CONFIRMED M4: parked messages or 1 MiB carries exhausted a 64M worker: ' . json_encode($Evidence)
      );
   }
);
