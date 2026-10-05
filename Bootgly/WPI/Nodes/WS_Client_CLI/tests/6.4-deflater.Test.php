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
use Bootgly\WPI\Modules\WS\Inflater;
use Bootgly\WPI\Nodes\WS_Client_CLI;
use Bootgly\WPI\Nodes\WS_Client_CLI\Handshake;
use Bootgly\WPI\Nodes\WS_Client_CLI\Session;


/**
 * H-WS-1 — the client compresses through the shared RFC 7692 Deflater: under
 * `client_no_context_takeover` each message decodes on its own and the
 * compressor is never re-created; with context takeover the window carries
 * over; an empty message is the single `0x00` octet in both modes.
 */
return new Test(
   description: 'H-WS-1: WS client compresses each message per the negotiated context takeover',
   skip: extension_loaded('zlib') === false,

   test: function () {
      $Evidence = [
         'error' => '',
         'dropped' => null,
         'kept' => null,
         'flags' => null,
      ];
      $Pairs = [];
      /** @var array<int,Session> $Sessions */
      $Sessions = [];

      try {
         $Client = new WS_Client_CLI(WS_Client_CLI::MODE_TEST);
         $Client->configure(new WS_Client_CLI\Configs(
            host: '127.0.0.1',
            port: 1,
            compression: true
         ));
         $Client->reset();
         /** A client session compressed with the server's `$answer`. */
         $Open = static function (string $answer) use (&$Pairs, &$Sessions, $Client): Session {
            $Pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            if ($Pair === false) {
               throw new RuntimeException('could not create a socket pair');
            }
            $Pairs[] = $Pair;
            $Session = new Session(new Connection($Pair[0], Client: $Client), 'H-WS-1-key', $Client);
            $Session->compress(Handshake::resolve($answer));
            $Sessions[] = $Session;

            return $Session;
         };
         /** A fresh (`$Peer` null) or the given persistent inflater's reading of `$wire`. */
         $Read = static function (string $wire, null|InflateContext $Peer = null): string|int|false {
            $Peer ??= inflate_init(ZLIB_ENCODING_RAW, ['window' => 15]);
            if ($Peer === false) {
               throw new RuntimeException('could not create an inflater');
            }

            return Inflater::inflate($Peer, $wire, 1 << 20);
         };
         $text = 'client ' . bin2hex(random_bytes(32));

         // # client_no_context_takeover: each message decodes on its own
         $Session = $Open('permessage-deflate; server_no_context_takeover; client_no_context_takeover');
         $Deflator = $Session->Deflator;
         [$first, $rsv1] = $Session->deflate($text);
         [$second] = $Session->deflate($text);
         [$empty] = $Session->deflate('');
         $Evidence['dropped'] = [
            $Session->clientNoContextTakeover,
            $rsv1,
            $Read($second) === $text,
            $second === $first,
            bin2hex($empty),
            $Session->Deflator !== null && $Session->Deflator === $Deflator,
         ];

         // # Context takeover: the window carries over; an empty message keeps the peer in step
         $Session = $Open('permessage-deflate');
         $Peer = inflate_init(ZLIB_ENCODING_RAW, ['window' => 15]);
         if ($Peer === false) {
            throw new RuntimeException('could not create an inflater');
         }
         $decoded = [];
         $wires = [];
         foreach ([$text, '', $text] as $part) {
            [$wire] = $Session->deflate($part);
            $wires[] = $wire;
            $decoded[] = $Read($wire, $Peer);
         }
         $Evidence['kept'] = [
            $Session->clientNoContextTakeover,
            $decoded === [$text, '', $text],
            bin2hex($wires[1]),
            strlen($wires[2]) < strlen($wires[0]),
         ];

         // # Only client_no_context_takeover decides the client's flush
         $Only = $Open('permessage-deflate; client_no_context_takeover');
         $Only->deflate($text);
         [$again] = $Only->deflate($text);
         $Other = $Open('permessage-deflate; server_no_context_takeover');
         [$one] = $Other->deflate($text);
         [$two] = $Other->deflate($text);
         $Evidence['flags'] = [$Read($again) === $text, strlen($two) < strlen($one)];
      }
      catch (Throwable $Throwable) {
         $Evidence['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         $Sessions = [];
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
            && $Evidence['dropped'] === [true, 0x40, true, true, '00', true],
         description: 'H-WS-1 without client context takeover each message must decode on its own: '
            . json_encode($Evidence)
      );
      yield assert(
         assertion: $Evidence['kept'] === [false, true, '00', true]
            && $Evidence['flags'] === [true, true],
         description: 'H-WS-1 with context takeover the window must carry over, an empty message included: '
            . json_encode($Evidence)
      );
   }
);
