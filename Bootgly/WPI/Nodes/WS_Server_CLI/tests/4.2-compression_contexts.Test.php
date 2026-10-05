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
use Bootgly\WPI\Modules\WS\Deflater;
use Bootgly\WPI\Modules\WS\Inflater;
use Bootgly\WPI\Nodes\WS_Client_CLI\Message\Frame as ClientFrame;
use Bootgly\WPI\Nodes\WS_Server_CLI\Decoders\Decoder_Framing;
use Bootgly\WPI\Nodes\WS_Server_CLI\Handshake;
use Bootgly\WPI\Nodes\WS_Server_CLI\Message\Frame as ServerFrame;
use Bootgly\WPI\Nodes\WS_Server_CLI\Session;


/**
 * H-WS-1 — a negotiated permessage-deflate session holds no zlib context of
 * its own. The server imposes no context takeover both ways: outbound
 * messages share one compressor per worker and window, flushed in full after
 * every message (no message carries another session's bytes), and each
 * inbound message is inflated by a fresh inflater (a client that keeps its
 * window gets 1007). An empty message is the single `0x00` octet.
 */
return new Test(
   description: 'H-WS-1: compressed sessions share one compressor per worker and inflate each message on its own',
   skip: extension_loaded('zlib') === false,

   test: function () {
      $Evidence = [
         'error' => '',
         'flags' => null,
         'memory' => null,
         'shared' => null,
         'outbound' => null,
         'narrow' => null,
         'control' => null,
         'inbound' => null,
         'takeover' => null,
         'empty' => null,
         'gate' => null,
      ];
      $Pairs = [];
      /** @var array<int,Session> $Sessions */
      $Sessions = [];
      $previousIdle = TCP_Server_CLI::$connectionIdleTimeout;

      try {
         TCP_Server_CLI::$connectionIdleTimeout = 0;
         $Decoder = new Decoder_Framing;
         $params = Handshake::resolve('permessage-deflate');

         /**
          * A session on its own socket (its own connection id), compressed
          * with `$offer` unless it is ''.
          */
         $Open = static function (string $offer = 'permessage-deflate') use (&$Pairs, &$Sessions): Session {
            $Pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            if ($Pair === false) {
               throw new RuntimeException('could not create a socket pair');
            }
            $Pairs[] = $Pair;
            $Connection = new Connection($Pair[0], '127.0.0.1', 50000 + count($Sessions));
            $Session = new Session($Connection);
            $Connection->decoded = $Session;
            if ($offer !== '') {
               $Session->compress(Handshake::resolve($offer));
            }
            $Sessions[] = $Session;

            return $Session;
         };
         /** Feed one client frame; the payload of the message it completed, if any. */
         $Feed = static function (Session $Session, string $wire) use ($Decoder): null|string {
            $Session->Message = null;
            $Session->Connection->consumed = 0;
            $Decoder->decode($Session->Connection, $wire, strlen($wire));

            return $Session->Message?->payload;
         };
         /** The close code a session queued, if any. */
         $Code = static function (Session $Session): null|int {
            $Close = ServerFrame::decode($Session->outbox, 0, 1024);
            if ($Close === null || $Close->opcode !== WS::OPCODE_CLOSE || strlen($Close->payload) < 2) {
               return null;
            }
            $unpacked = unpack('n', substr($Close->payload, 0, 2));

            return is_array($unpacked) ? (int) $unpacked[1] : null;
         };
         /** Decode a compressed payload with a fresh inflater (no earlier message known). */
         $Fresh = static function (string $wire): string|int|false {
            $Inflator = inflate_init(ZLIB_ENCODING_RAW, ['window' => 15]);
            if ($Inflator === false) {
               throw new RuntimeException('could not create an inflater');
            }

            return Inflater::inflate($Inflator, $wire, 1 << 20);
         };
         /** Decode byte by byte with a `$window`-bit inflater — zlib enforces the window across calls. */
         $Bytewise = static function (string $wire, int $window): string|false {
            $Inflator = inflate_init(ZLIB_ENCODING_RAW, ['window' => $window]);
            if ($Inflator === false) {
               throw new RuntimeException('could not create an inflater');
            }
            $out = '';
            set_error_handler(static fn (): bool => true, E_WARNING);
            try {
               foreach (str_split("{$wire}\x00\x00\xff\xff") as $byte) {
                  $part = inflate_add($Inflator, $byte, ZLIB_SYNC_FLUSH);
                  if ($part === false) {
                     return false;
                  }
                  $out .= $part;
               }
            }
            finally {
               restore_error_handler();
            }

            return $out;
         };
         $Compress = static fn (Session $Session, string $text): string
            => $Session->deflate($text)[0];

         // # The server answers both no-context-takeover flags, offered or not
         $Bare = $Open('');
         $Bare->compress(['permessage-deflate' => true]);
         $Evidence['flags'] = [
            Handshake::extend($params),
            Handshake::extend(Handshake::resolve('permessage-deflate; server_max_window_bits=10; client_max_window_bits')),
            Handshake::extend(Handshake::resolve('permessage-deflate; server_max_window_bits=15')),
            // @ An out-of-range bound declines that offer; the next one is tried
            Handshake::resolve('permessage-deflate; server_max_window_bits=8'),
            Handshake::extend(Handshake::resolve('permessage-deflate; server_max_window_bits=8, permessage-deflate')),
            $Bare->extensions['server_no_context_takeover'] ?? null,
            $Bare->extensions['client_no_context_takeover'] ?? null,
            $Bare->serverNoContextTakeover,
            $Bare->clientNoContextTakeover,
         ];

         // # A compressed session holds no zlib context of its own
         $Open();
         gc_collect_cycles();
         $before = memory_get_usage();
         for ($index = 0; $index < 64; $index++) {
            $Open();
         }
         $Evidence['memory'] = intdiv(memory_get_usage() - $before, 64);

         // # One compressor per worker and window; no inflater between messages
         $A = $Open();
         $B = $Open();
         $Narrow = $Open('permessage-deflate; server_max_window_bits=10');
         $NarrowB = $Open('permessage-deflate; server_max_window_bits=10');
         $Evidence['shared'] = [
            $A->Deflator !== null && $A->Deflator === $B->Deflator,
            $Narrow->Deflator !== null && $Narrow->Deflator !== $A->Deflator,
            $Narrow->Deflator === $NarrowB->Deflator,
            $A->Inflator === null,
         ];

         // # Outbound: no message refers to another session's bytes
         $secret = 'session A secret token=' . bin2hex(random_bytes(24));
         $message = "B says: {$secret} / {$secret}";
         $wireA = $Compress($A, $secret);
         [$wireB, $rsv1] = $B->deflate($message);
         $Evidence['outbound'] = [
            $Fresh($wireA) === $secret,
            $Fresh($wireB) === $message,
            $rsv1,
            strlen($wireB) < strlen($message),
         ];
         // @ The same through a narrow window, which also stays within its bound
         $blob = random_bytes(2048);
         $Evidence['narrow'] = [
            $Fresh($Compress($Narrow, $secret)) === $secret,
            $Fresh($Compress($NarrowB, $message)) === $message,
            $Bytewise($Compress($NarrowB, "{$blob}{$blob}"), 10) === "{$blob}{$blob}",
         ];
         // ! Control: the same two messages through one context-takeover
         //   compressor — the second is not decodable on its own
         $Takeover = deflate_init(ZLIB_ENCODING_RAW, ['window' => 15, 'level' => -1]);
         if ($Takeover === false) {
            throw new RuntimeException('could not create a deflater');
         }
         Deflater::deflate($Takeover, $secret, true);
         $Evidence['control'] = $Fresh((string) Deflater::deflate($Takeover, $message, true)) === $message;

         // # Inbound: each message inflates on its own
         $text = 'inbound ' . bin2hex(random_bytes(32));
         $C = $Open();
         $Client = deflate_init(ZLIB_ENCODING_RAW, ['window' => 15, 'level' => -1]);
         if ($Client === false) {
            throw new RuntimeException('could not create a deflater');
         }
         $received = [];
         for ($index = 0; $index < 2; $index++) {
            $wire = (string) Deflater::deflate($Client, $text, false);
            $received[] = $Feed($C, ClientFrame::encode(WS::OPCODE_TEXT, $wire, rsv1: 0x40));
         }
         // @ A narrow-window session inflates with a full window — a match
         //   farther back than 2^14 + one inflate chunk (4096) needs window 15
         $far = random_bytes(24576);
         $received[] = $Feed($NarrowB, ClientFrame::encode(
            WS::OPCODE_BINARY,
            (string) Deflater::deflate($Client, "{$far}{$far}", false),
            rsv1: 0x40
         )) === "{$far}{$far}";
         $Evidence['inbound'] = [$received === [$text, $text, true], $C->Inflator === null, $C->disconnected];

         // # A client that keeps its window refers to its previous message: 1007
         $D = $Open();
         $Client = deflate_init(ZLIB_ENCODING_RAW, ['window' => 15, 'level' => -1]);
         if ($Client === false) {
            throw new RuntimeException('could not create a deflater');
         }
         $first = $Feed($D, ClientFrame::encode(WS::OPCODE_TEXT, (string) Deflater::deflate($Client, $text, true), rsv1: 0x40));
         $second = $Feed($D, ClientFrame::encode(WS::OPCODE_TEXT, (string) Deflater::deflate($Client, $text, true), rsv1: 0x40));
         $Evidence['takeover'] = [$first === $text, $second, $D->disconnected, $Code($D), $D->Inflator === null];

         // # An empty message is one 0x00 octet; a peer keeping its window stays in step
         $E = $Open();
         $Peer = inflate_init(ZLIB_ENCODING_RAW, ['window' => 15]);
         if ($Peer === false) {
            throw new RuntimeException('could not create an inflater');
         }
         $wires = [];
         $decoded = [];
         foreach (['one', '', 'three'] as $part) {
            $wires[] = bin2hex($Compress($E, $part));
            $decoded[] = Inflater::inflate($Peer, (string) hex2bin(end($wires)), 1 << 20);
         }
         $Context = deflate_init(ZLIB_ENCODING_RAW, ['window' => 15, 'level' => -1]);
         if ($Context === false) {
            throw new RuntimeException('could not create a deflater');
         }
         Deflater::deflate($Context, 'one', true);
         $Evidence['empty'] = [$wires[1], $decoded, bin2hex((string) Deflater::deflate($Context, '', true))];

         // # RSV1 follows the negotiated extension, not the outbound compressor
         $Plain = $Open('');
         $Feed($Plain, ClientFrame::encode(WS::OPCODE_TEXT, (string) hex2bin($wires[0]), rsv1: 0x40));
         $Muted = $Open();
         $Muted->Deflator = null;
         $payload = $Feed($Muted, ClientFrame::encode(WS::OPCODE_TEXT, (string) hex2bin($wires[0]), rsv1: 0x40));
         $Evidence['gate'] = [$Code($Plain), $payload, $Muted->disconnected, $Open('')->inflate('x')];
      }
      catch (Throwable $Throwable) {
         $Evidence['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         foreach ($Sessions as $Session) {
            $Session->disconnect();
         }
         $Sessions = [];
         TCP_Server_CLI::$connectionIdleTimeout = $previousIdle;
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
            && $Evidence['flags'] === [
               'permessage-deflate; server_no_context_takeover; client_no_context_takeover',
               'permessage-deflate; server_no_context_takeover; client_no_context_takeover; server_max_window_bits=10',
               'permessage-deflate; server_no_context_takeover; client_no_context_takeover; server_max_window_bits=15',
               [],
               'permessage-deflate; server_no_context_takeover; client_no_context_takeover',
               true,
               true,
               true,
               true,
            ],
         description: 'H-WS-1 the server must impose no context takeover both ways: ' . json_encode($Evidence)
      );
      yield assert(
         assertion: is_int($Evidence['memory']) && $Evidence['memory'] < 8_192
            && $Evidence['shared'] === [true, true, true, true],
         description: 'H-WS-1 a compressed session must hold no zlib context of its own: ' . json_encode($Evidence)
      );
      yield assert(
         assertion: $Evidence['outbound'] === [true, true, 0x40, true]
            && $Evidence['narrow'] === [true, true, true]
            && $Evidence['control'] === false,
         description: 'H-WS-1 an outbound message must decode on its own, never referring to another session: '
            . json_encode($Evidence)
      );
      yield assert(
         assertion: $Evidence['inbound'] === [true, true, false]
            && $Evidence['takeover'] === [true, null, true, 1007, true],
         description: 'H-WS-1 each inbound message must inflate on its own (a kept window closes 1007): '
            . json_encode($Evidence)
      );
      yield assert(
         assertion: $Evidence['empty'] === ['00', ['one', '', 'three'], '00']
            && $Evidence['gate'] === [1002, 'one', false, 'x'],
         description: 'H-WS-1 an empty message must be the 0x00 octet; RSV1 follows the extension: '
            . json_encode($Evidence)
      );
   }
);
