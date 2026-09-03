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
use Bootgly\WPI\Interfaces\TCP_Client_CLI\Connections\Connection;
use Bootgly\WPI\Modules\WS;
use Bootgly\WPI\Nodes\WS_Client_CLI;
use Bootgly\WPI\Nodes\WS_Client_CLI\Decoders\Decoder_Framing;
use Bootgly\WPI\Nodes\WS_Client_CLI\Message\Frame as ClientFrame;
use Bootgly\WPI\Nodes\WS_Client_CLI\Session;
use Bootgly\WPI\Nodes\WS_Server_CLI\Message\Frame as ServerFrame;


/**
 * H4 PoC — the client decoder must enforce the decompressed-message budget
 * while inflating a server frame, not after materializing the full expansion.
 */
return new Test(
   description: 'H4: WS client bounds permessage-deflate output during inflation',
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
         'compressed_bytes' => 0,
         'expanded_bytes' => $expanded,
         'limit' => $limit,
         'stop' => false,
         'fragmented' => false,
         'message' => false,
         'close' => null,
         'clean' => false,
         'peak_delta' => 0,
         'peak_limit' => $peakLimit,
      ];
      $Listener = null;
      $Writer = null;
      $Reader = null;

      try {
         $controlPayload = 'H4-client-control';
         $control = $compress($controlPayload);
         $attack = $bomb($expanded);
         if (is_string($control) === false || is_string($attack) === false) {
            throw new RuntimeException('could not build valid raw-deflate fixtures');
         }
         $Evidence['compressed_bytes'] = strlen($attack);

         $Listener = stream_socket_server(
            'tcp://127.0.0.1:0',
            $code,
            $message,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
         );
         $address = is_resource($Listener)
            ? stream_socket_get_name($Listener, false)
            : false;
         $Writer = is_string($address)
            ? stream_socket_client("tcp://{$address}", $code, $message, 1.0)
            : false;
         $Reader = is_resource($Listener)
            ? stream_socket_accept($Listener, 1.0)
            : false;
         if (is_resource($Writer) === false || is_resource($Reader) === false) {
            throw new RuntimeException('could not create the client decoder TCP pair');
         }

         $Client = new WS_Client_CLI(WS_Client_CLI::MODE_TEST);
         $Client->configure(new WS_Client_CLI\Configs(
            host: '127.0.0.1',
            port: 1,
            maxMessageSize: $limit,
            compression: true
         ));
         $Client->reset();

         $Connection = new Connection($Writer, Client: $Client);
         $Session = new Session($Connection, 'H4-test-key', $Client);
         $Session->compress([
            'permessage-deflate' => true,
            'server_no_context_takeover' => true,
         ]);
         $Client->Session = $Session;
         $Decoder = new Decoder_Framing;

         $wire = ServerFrame::encode(
            WS::OPCODE_BINARY,
            $control,
            rsv1: 0x40
         );
         $result = $Decoder->decode($Session, $wire);
         $Evidence['control'] = is_array($result)
            && ($result['message'] ?? null)?->payload === $controlPayload;

         // @ Exact boundary — secure code accepts the last permitted byte.
         $boundaryPayload = str_repeat('B', $limit);
         $boundary = $compress($boundaryPayload);
         if (is_string($boundary) === false) {
            throw new RuntimeException('could not build the exact-boundary fixture');
         }
         $Session->Message = null;
         $wire = ServerFrame::encode(
            WS::OPCODE_BINARY,
            $boundary,
            rsv1: 0x40
         );
         $result = $Decoder->decode($Session, $wire);
         $Evidence['boundary'] = is_array($result)
            && ($result['message'] ?? null)?->payload === $boundaryPayload;

         $Session->Message = null;
         gc_collect_cycles();
         memory_reset_peak_usage();
         $before = memory_get_usage();

         $split = intdiv(strlen($attack), 2);
         $first = ServerFrame::encode(
            WS::OPCODE_BINARY,
            substr($attack, 0, $split),
            fin: false,
            rsv1: 0x40
         );
         $second = ServerFrame::encode(
            WS::OPCODE_CONTINUATION,
            substr($attack, $split),
            fin: true
         );
         $firstResult = $Decoder->decode($Session, $first);
         $Evidence['fragmented'] = is_array($firstResult)
            && isset($firstResult['message']) === false
            && ($firstResult['stop'] ?? false) === false;
         $result = $Decoder->decode($Session, $second);
         $Evidence['peak_delta'] = max(0, memory_get_peak_usage() - $before);
         $Evidence['stop'] = is_array($result) && ($result['stop'] ?? false) === true;
         $Evidence['message'] = $Session->Message !== null;
         $Evidence['clean'] = $Session->reassembly === ''
            && $Session->reassemblyOpcode === 0
            && $Session->reassemblyCompressed === false
            && $Session->utf8Pending === ''
            && $Session->Inflator === null;

         $closeWire = stream_get_contents($Reader);
         $Close = is_string($closeWire)
            ? ClientFrame::decode($closeWire, 0, 1024)
            : null;
         $closeCode = $Close !== null && strlen($Close->payload) >= 2
            ? unpack('ncode', substr($Close->payload, 0, 2))
            : false;
         $Evidence['close'] = is_array($closeCode)
            ? (int) $closeCode['code']
            : null;
      }
      catch (Throwable $Throwable) {
         $Evidence['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         foreach ([$Writer, $Reader, $Listener] as $Stream) {
            if (is_resource($Stream)) {
               fclose($Stream);
            }
         }
      }

      yield assert(
         assertion: $Evidence['error'] === '' && $Evidence['control'] === true,
         description: 'H4 client control must decode a small compressed binary message: '
            . json_encode($Evidence)
      );
      yield assert(
         assertion: $Evidence['boundary'] === true,
         description: 'H4 client must accept a message at the exact limit: '
            . json_encode($Evidence)
      );
      yield assert(
         assertion: $Evidence['compressed_bytes'] > 0
            && $Evidence['compressed_bytes'] < $limit,
         description: 'H4 client attack fixture must be valid and fit the compressed budget: '
            . json_encode($Evidence)
      );
      yield assert(
         assertion: $Evidence['stop'] === true
            && $Evidence['fragmented'] === true
            && $Evidence['message'] === false
            && $Evidence['close'] === 1009
            && $Evidence['clean'] === true,
         description: 'H4 client must reject the expanded message with close 1009: '
            . json_encode($Evidence)
      );
      yield assert(
         assertion: $Evidence['peak_delta'] <= $peakLimit,
         description: 'CONFIRMED H4: WS client materialized decompressed attacker output '
            . 'before enforcing maxMessageSize; evidence=' . json_encode($Evidence)
      );
   }
);
