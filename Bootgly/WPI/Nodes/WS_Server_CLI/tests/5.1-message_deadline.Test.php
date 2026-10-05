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
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Modules\WS;
use Bootgly\WPI\Nodes\WS_Client_CLI\Message\Frame as ClientFrame;
use Bootgly\WPI\Nodes\WS_Server_CLI\Decoders\Decoder_Framing;
use Bootgly\WPI\Nodes\WS_Server_CLI\Message\Frame as ServerFrame;
use Bootgly\WPI\Nodes\WS_Server_CLI\Session;


/**
 * M4 (liveness) — inbound bytes keep a WebSocket peer "active", so the
 * heartbeat never probes a peer that drips a frame (or an open fragmented
 * message) forever. The message deadline closes it with 1008 anyway.
 */
return new Test(
   description: 'M4: an unfinished inbound message is closed with 1008 at its deadline',
   test: function () {
      $Evidence = [
         'error' => '',
         'early' => null,
         'drip' => null,
         'fragment' => null,
         'interleaved' => null,
         'chatty' => null,
         'finished' => null,
         'released' => null,
         'disabled' => null,
      ];
      $Pairs = [];
      /** @var array<int,Session> $Sessions */
      $Sessions = [];
      $previousIdle = TCP_Server_CLI::$connectionIdleTimeout;
      $previousHeartbeat = Session::$heartbeatInterval;
      $previousTimeout = null;

      try {
         $previousTimeout = Session::$maxMessageWallTime;
         TCP_Server_CLI::$connectionIdleTimeout = 0;
         // ! The heartbeat never decides here: activity is always fresh.
         Session::$heartbeatInterval = 3600;
         Session::$maxMessageWallTime = 2;
         $Decoder = new Decoder_Framing;

         $Open = static function () use (&$Pairs, &$Sessions): array {
            $Pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            if ($Pair === false) {
               throw new RuntimeException('could not create a socket pair');
            }
            stream_set_blocking($Pair[1], false);
            $Pairs[] = $Pair;
            $Connection = new Connection($Pair[0], '127.0.0.1', 40000 + count($Pairs));
            $Session = new Session($Connection);
            $Connection->decoded = $Session;
            $Sessions[] = $Session;

            return [$Connection, $Session, $Pair[1]];
         };
         $Feed = static function (Connection $Connection, string $wire) use ($Decoder): string {
            $Connection->consumed = 0;

            return $Decoder->decode($Connection, $wire, strlen($wire))->name;
         };
         $Code = static function ($Peer): null|int {
            $bytes = (string) @fread($Peer, 65536);
            $Close = ServerFrame::decode($bytes, 0, 1024);
            if ($Close === null || $Close->opcode !== WS::OPCODE_CLOSE || strlen($Close->payload) < 2) {
               return null;
            }
            $unpacked = unpack('n', substr($Close->payload, 0, 2));

            return is_array($unpacked) ? (int) $unpacked[1] : null;
         };

         // @ drip: a 1 MiB frame announced, 4 KiB sent, then one byte per tick.
         [$DripConnection, $Drip, $DripPeer] = $Open();
         $whole = ClientFrame::encode(WS::OPCODE_BINARY, str_repeat('D', 1048576));
         $sent = 4096;
         $Feed($DripConnection, substr($whole, 0, $sent));
         // @ fragment: an open message whose frames all complete, never FIN.
         [$FragmentConnection, $Fragment, $FragmentPeer] = $Open();
         $Feed($FragmentConnection, ClientFrame::encode(WS::OPCODE_TEXT, 'x', fin: false));
         // @ interleaved: an open message kept busy with PINGs between fragments
         [$InterleavedConnection, $Interleaved, $InterleavedPeer] = $Open();
         $Feed($InterleavedConnection, ClientFrame::encode(WS::OPCODE_TEXT, 'p', fin: false));
         // @ chatty (control): one complete message per tick.
         [$ChattyConnection, $Chatty, $ChattyPeer] = $Open();
         // @ finished (control): a partial frame that completes at once.
         [$FinishedConnection, $Finished, $FinishedPeer] = $Open();
         $small = ClientFrame::encode(WS::OPCODE_BINARY, str_repeat('F', 1000));
         $Feed($FinishedConnection, substr($small, 0, 10));
         $Feed($FinishedConnection, substr($small, 10));
         // @ pinged (control): a partial PING outside any message completes.
         [$PingedConnection, $Pinged, $PingedPeer] = $Open();
         $ping = ClientFrame::encode(WS::OPCODE_PING, 'live');
         $Feed($PingedConnection, substr($ping, 0, 3));
         $Feed($PingedConnection, substr($ping, 3));
         (string) @fread($PingedPeer, 65536); // drain the auto-pong

         // ? Before the deadline nobody is closed.
         foreach ([$Drip, $Fragment, $Interleaved, $Chatty, $Finished, $Pinged] as $Session) {
            $Session->supervise();
         }
         $Evidence['early'] = $Drip->disconnected === false && $Fragment->disconnected === false
            && $Interleaved->disconnected === false;

         // @ Keep every peer active until the drip deadline passes.
         $deadline = $Drip->deadline;
         $tick = 0;
         while (hrtime(true) < $deadline && $tick < 40) {
            usleep(100000);
            $tick++;
            $Feed($DripConnection, $whole[$sent++]);
            $Feed($FragmentConnection, ClientFrame::encode(WS::OPCODE_CONTINUATION, 'y', fin: false));
            $Feed($InterleavedConnection, ClientFrame::encode(WS::OPCODE_PING, "k{$tick}"));
            $Feed($InterleavedConnection, ClientFrame::encode(WS::OPCODE_CONTINUATION, 'q', fin: false));
            (string) @fread($InterleavedPeer, 65536); // drain the auto-pongs
            $Feed($ChattyConnection, ClientFrame::encode(WS::OPCODE_TEXT, "m{$tick}"));
         }
         foreach ([$Drip, $Fragment, $Interleaved, $Chatty, $Finished, $Pinged] as $Session) {
            if ($Session->disconnected === false) {
               $Session->supervise();
            }
         }

         $Evidence['drip'] = [$Drip->disconnected, $Code($DripPeer)];
         $Evidence['fragment'] = [$Fragment->disconnected, $Code($FragmentPeer)];
         $Evidence['interleaved'] = [$Interleaved->disconnected, $Code($InterleavedPeer)];
         $Evidence['chatty'] = [$Chatty->disconnected, $Code($ChattyPeer)];
         $Evidence['finished'] = [$Finished->disconnected, $Code($FinishedPeer), $Pinged->disconnected, $Code($PingedPeer)];
         $Evidence['released'] = $Drip->carry === '' && $Fragment->reassembly === ''
            && $Drip->Buffers->retained === 0 && $Fragment->Buffers->retained === 0;

         // @ A non-positive wall time fails closed: clamped to one second.
         Session::$maxMessageWallTime = 0;
         [$ClampConnection, $Clamp] = $Open();
         $before = hrtime(true);
         $Feed($ClampConnection, substr($whole, 0, 100));
         $after = hrtime(true);
         $Evidence['disabled'] = $Clamp->deadline >= $before + 1_000_000_000
            && $Clamp->deadline <= $after + 1_000_000_000;
      }
      catch (Throwable $Throwable) {
         $Evidence['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         // ! Every session returns its hold to the shared ledger
         foreach ($Sessions as $Session) {
            $Session->disconnect();
         }
         TCP_Server_CLI::$connectionIdleTimeout = $previousIdle;
         Session::$heartbeatInterval = $previousHeartbeat;
         if ($previousTimeout !== null) {
            Session::$maxMessageWallTime = $previousTimeout;
         }
         foreach ($Pairs as $Pair) {
            foreach ($Pair as $Stream) {
               if (is_resource($Stream)) {
                  fclose($Stream);
               }
            }
         }
      }

      yield assert(
         assertion: $Evidence['error'] === '' && $Evidence['early'] === true,
         description: 'M4 sessions must stay open before the deadline: ' . json_encode($Evidence)
      );
      yield assert(
         assertion: $Evidence['drip'] === [true, 1008] && $Evidence['fragment'] === [true, 1008]
            && $Evidence['interleaved'] === [true, 1008],
         description: 'M4 a dripped frame and an open message must close with 1008 at the deadline: '
            . json_encode($Evidence)
      );
      yield assert(
         assertion: $Evidence['chatty'] === [false, null] && $Evidence['finished'] === [false, null, false, null],
         description: 'M4 completed messages must never trip the deadline: ' . json_encode($Evidence)
      );
      yield assert(
         assertion: $Evidence['released'] === true && $Evidence['disabled'] === true,
         description: 'M4 a reaped session must release its bytes; the wall time is clamped to >= 1 s: '
            . json_encode($Evidence)
      );
   }
);
