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
use Bootgly\WPI\Endpoints\Servers\Decoder\States;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Modules\WS;
use Bootgly\WPI\Nodes\WS_Client_CLI\Message\Frame as ClientFrame;
use Bootgly\WPI\Nodes\WS_Server_CLI\Decoders\Decoder_Framing;
use Bootgly\WPI\Nodes\WS_Server_CLI\Message\Frame as ServerFrame;
use Bootgly\WPI\Nodes\WS_Server_CLI\Session;


/**
 * H4 PoC — the server decoder must enforce the decompressed-message budget
 * while inflating, not after one attacker-controlled expansion is materialized.
 */
return new Test(
   description: 'H4: WS server bounds permessage-deflate output during inflation',
   skip: extension_loaded('zlib') === false
      || function_exists('memory_reset_peak_usage') === false,

   test: function () {
      $limit = 65536;
      $expanded = 8 * 1024 * 1024;
      $peakLimit = 2 * 1024 * 1024;

      $compress = static function (string $payload): string|false {
         $Deflator = deflate_init(ZLIB_ENCODING_RAW, ['window' => 15, 'level' => 9]);
         if ($Deflator === false) {
            return false;
         }

         $compressed = deflate_add($Deflator, $payload, ZLIB_SYNC_FLUSH);
         if ($compressed === false) {
            return false;
         }

         return str_ends_with($compressed, "\x00\x00\xff\xff")
            ? (string) substr($compressed, 0, -4)
            : $compressed;
      };
      $bomb = static function (int $bytes): string|false {
         $Deflator = deflate_init(ZLIB_ENCODING_RAW, ['window' => 15, 'level' => 9]);
         if ($Deflator === false) {
            return false;
         }

         $unit = str_repeat('H', 1024 * 1024);
         $compressed = '';
         for ($remaining = $bytes; $remaining > 0;) {
            $size = min(strlen($unit), $remaining);
            $input = $size === strlen($unit) ? $unit : substr($unit, 0, $size);
            $part = deflate_add(
               $Deflator,
               $input,
               $remaining === $size ? ZLIB_SYNC_FLUSH : ZLIB_NO_FLUSH
            );
            if ($part === false) {
               return false;
            }
            $compressed .= $part;
            $remaining -= $size;
         }

         return str_ends_with($compressed, "\x00\x00\xff\xff")
            ? (string) substr($compressed, 0, -4)
            : $compressed;
      };

      $Evidence = [
         'error' => '',
         'control' => false,
         'boundary' => false,
         'bfinal' => false,
         'compressed_bytes' => 0,
         'expanded_bytes' => $expanded,
         'limit' => $limit,
         'state' => '',
         'fragmented' => false,
         'message' => false,
         'close' => null,
         'clean' => false,
         'peak_delta' => 0,
         'peak_limit' => $peakLimit,
      ];
      $Pair = false;
      $Socket = null;
      $previousIdle = TCP_Server_CLI::$connectionIdleTimeout;
      $previousLimit = Session::$maxMessageSize;

      try {
         $controlPayload = 'H4-server-control';
         $control = $compress($controlPayload);
         $attack = $bomb($expanded);
         if (is_string($control) === false || is_string($attack) === false) {
            throw new RuntimeException('could not build valid raw-deflate fixtures');
         }

         $Evidence['compressed_bytes'] = strlen($attack);

         TCP_Server_CLI::$connectionIdleTimeout = 0;
         Session::$maxMessageSize = $limit;

         $Pair = stream_socket_pair(
            STREAM_PF_UNIX,
            STREAM_SOCK_STREAM,
            STREAM_IPPROTO_IP
         );
         if ($Pair === false) {
            throw new RuntimeException('could not create the server decoder socket pair');
         }
         $Socket = $Pair[0];

         $Connection = new Connection($Socket, '127.0.0.1', 65535);
         $Session = new Session($Connection);
         $Session->compress([
            'permessage-deflate' => true,
            'client_no_context_takeover' => true,
         ]);
         $Connection->decoded = $Session;
         $Decoder = new Decoder_Framing;

         $wire = ClientFrame::encode(
            WS::OPCODE_BINARY,
            $control,
            rsv1: 0x40
         );
         $state = $Decoder->decode($Connection, $wire, strlen($wire));
         $Evidence['control'] = $state === States::Complete
            && $Session->Message?->payload === $controlPayload;

         // @ Exact boundary — secure code accepts the last permitted byte.
         $boundaryPayload = str_repeat('B', $limit);
         $boundary = $compress($boundaryPayload);
         if (is_string($boundary) === false) {
            throw new RuntimeException('could not build the exact-boundary fixture');
         }
         $Session->Message = null;
         $wire = ClientFrame::encode(
            WS::OPCODE_BINARY,
            $boundary,
            rsv1: 0x40
         );
         $state = $Decoder->decode($Connection, $wire, strlen($wire));
         $Evidence['boundary'] = $state === States::Complete
            && $Session->Message?->payload === $boundaryPayload;

         // @ RFC 7692 permits a BFINAL=1 stream. Preserve the published
         //   "Hello" vector instead of interpreting the tail as another stream.
         $final = hex2bin('f348cdc9c9070000');
         if ($final === false) {
            throw new RuntimeException('could not build the BFINAL fixture');
         }
         $Session->Message = null;
         $wire = ClientFrame::encode(
            WS::OPCODE_BINARY,
            $final,
            rsv1: 0x40
         );
         $state = $Decoder->decode($Connection, $wire, strlen($wire));
         $Evidence['bfinal'] = $state === States::Complete
            && $Session->Message?->payload === 'Hello';

         $Session->Message = null;
         $Connection->consumed = 0;
         gc_collect_cycles();
         memory_reset_peak_usage();
         $before = memory_get_usage();

         $split = intdiv(strlen($attack), 2);
         $first = ClientFrame::encode(
            WS::OPCODE_BINARY,
            substr($attack, 0, $split),
            fin: false,
            rsv1: 0x40
         );
         $second = ClientFrame::encode(
            WS::OPCODE_CONTINUATION,
            substr($attack, $split),
            fin: true
         );
         $firstState = $Decoder->decode($Connection, $first, strlen($first));
         $Evidence['fragmented'] = $firstState === States::Complete
            && $Session->Message === null;
         $Connection->consumed = 0;
         $state = $Decoder->decode($Connection, $second, strlen($second));
         $Evidence['peak_delta'] = max(0, memory_get_peak_usage() - $before);
         $Evidence['state'] = $state->name;
         $Evidence['message'] = $Session->Message !== null;
         $Evidence['clean'] = $Session->reassembly === ''
            && $Session->reassemblyOpcode === 0
            && $Session->reassemblyCompressed === false
            && $Session->utf8Pending === ''
            && $Session->Inflator === null;

         $Close = ServerFrame::decode($Session->outbox, 0, 1024);
         $code = $Close !== null && strlen($Close->payload) >= 2
            ? unpack('ncode', substr($Close->payload, 0, 2))
            : false;
         $Evidence['close'] = is_array($code) ? (int) $code['code'] : null;
      }
      catch (Throwable $Throwable) {
         $Evidence['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         TCP_Server_CLI::$connectionIdleTimeout = $previousIdle;
         Session::$maxMessageSize = $previousLimit;

         if (is_array($Pair)) {
            foreach ($Pair as $Stream) {
               if (is_resource($Stream)) {
                  fclose($Stream);
               }
            }
         }
      }

      yield assert(
         assertion: $Evidence['error'] === '' && $Evidence['control'] === true,
         description: 'H4 server control must decode a small compressed binary message: '
            . json_encode($Evidence)
      );
      yield assert(
         assertion: $Evidence['boundary'] === true && $Evidence['bfinal'] === true,
         description: 'H4 server must accept the exact limit and RFC 7692 BFINAL=1: '
            . json_encode($Evidence)
      );
      yield assert(
         assertion: $Evidence['compressed_bytes'] > 0
            && $Evidence['compressed_bytes'] < $limit,
         description: 'H4 server attack fixture must be valid and fit the compressed budget: '
            . json_encode($Evidence)
      );
      yield assert(
         assertion: $Evidence['state'] === States::Complete->name
            && $Evidence['fragmented'] === true
            && $Evidence['message'] === false
            && $Evidence['close'] === 1009
            && $Evidence['clean'] === true,
         description: 'H4 server must reject the expanded message with close 1009: '
            . json_encode($Evidence)
      );
      yield assert(
         assertion: $Evidence['peak_delta'] <= $peakLimit,
         description: 'CONFIRMED H4: WS server materialized decompressed attacker output '
            . 'before enforcing maxMessageSize; evidence=' . json_encode($Evidence)
      );
   }
);
