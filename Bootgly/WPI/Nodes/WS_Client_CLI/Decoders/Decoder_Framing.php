<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\WS_Client_CLI\Decoders;


use function pack;
use function preg_match;
use function strlen;
use function substr;
use function time;
use function unpack;

use Bootgly\WPI\Modules\WS;
use Bootgly\WPI\Nodes\WS_Client_CLI\Decoders;
use Bootgly\WPI\Nodes\WS_Client_CLI\Message;
use Bootgly\WPI\Nodes\WS_Client_CLI\Message\Frame;
use Bootgly\WPI\Nodes\WS_Client_CLI\Message\UTF8;
use Bootgly\WPI\Nodes\WS_Client_CLI\Session;


/**
 * RFC 6455 frame decoder (client side). Mirrors the server's state machine —
 * reassembly, streaming UTF-8, size caps, control handling — with two client
 * deltas: server frames MUST NOT be masked (the guard is flipped), and control
 * echoes (auto-pong, close) are written immediately via `Session::deliver()`.
 */
class Decoder_Framing extends Decoders
{
   /**
    * @return null|array{consumed: int, message?: Message, stop?: true}
    */
   public function decode (Session $Session, string $buffer): null|array
   {
      $Session->lastActivity = time();

      // @ Decode a single frame from the head of the buffer.
      $Frame = Frame::decode($buffer, 0, $Session->Client->maxFrameSize);

      // ? Partial frame — wait for more bytes.
      if ($Frame === null) {
         return null;
      }

      // ? Fatal framing fault from the header (oversize / bad length).
      if ($Frame->error !== 0) {
         return $this->fail($Session, $Frame->error);
      }

      // ? RSV2/RSV3 must always be clear. RSV1 marks a permessage-deflate
      //   compressed message and is valid only on the first data frame when the
      //   extension was negotiated.
      if ($Frame->rsv2 !== 0 || $Frame->rsv3 !== 0) {
         return $this->fail($Session, 1002);
      }
      if (
         $Frame->rsv1 !== 0
         && (
            $Session->Inflator === null
            || $Frame->opcode === WS::OPCODE_CONTINUATION
            || $Frame->opcode >= WS::OPCODE_CLOSE
         )
      ) {
         return $this->fail($Session, 1002);
      }

      // ? Server-to-client frames MUST NOT be masked (§5.1).
      if ($Frame->masked === true) {
         return $this->fail($Session, 1002);
      }

      $opcode = $Frame->opcode;

      // # Control frames (close / ping / pong): <=125 bytes, never fragmented.
      if ($opcode >= WS::OPCODE_CLOSE) {
         if (
            $opcode !== WS::OPCODE_CLOSE
            && $opcode !== WS::OPCODE_PING
            && $opcode !== WS::OPCODE_PONG
         ) {
            return $this->fail($Session, 1002);
         }
         if ($Frame->length > 125 || $Frame->fin === false) {
            return $this->fail($Session, 1002);
         }

         return $this->handle($Session, $Frame);
      }

      // # Data frames (continuation / text / binary)
      if ($opcode === WS::OPCODE_CONTINUATION) {
         // ? Continuation without an in-progress message.
         if ($Session->reassemblyOpcode === 0) {
            return $this->fail($Session, 1002);
         }
         $Session->reassembly .= $Frame->payload;
      }
      else if ($opcode === WS::OPCODE_TEXT || $opcode === WS::OPCODE_BINARY) {
         // ? New data frame while a fragmented message is still open.
         if ($Session->reassemblyOpcode !== 0) {
            return $this->fail($Session, 1002);
         }
         $Session->reassemblyOpcode = $opcode;
         $Session->reassemblyCompressed = $Frame->rsv1 !== 0;
         $Session->reassembly = $Frame->payload;
         $Session->utf8Pending = '';
      }
      else {
         // ? Reserved data opcode (0x3..0x7).
         return $this->fail($Session, 1002);
      }

      // ? Cumulative message-size cap.
      if (strlen($Session->reassembly) > $Session->Client->maxMessageSize) {
         return $this->fail($Session, 1009);
      }

      // ? Incremental UTF-8 validation (fail-fast) for non-compressed text.
      if (
         $Session->reassemblyOpcode === WS::OPCODE_TEXT
         && $Session->reassemblyCompressed === false
      ) {
         $pending = UTF8::validate($Session->utf8Pending, $Frame->payload);
         if ($pending === null) {
            return $this->fail($Session, 1007);
         }
         $Session->utf8Pending = $pending;
      }

      // ? Final fragment — surface a complete message.
      if ($Frame->fin) {
         $messageOpcode = $Session->reassemblyOpcode;

         if ($Session->reassemblyCompressed) {
            $payload = $Session->inflate($Session->reassembly);
            // ? Invalid compressed data (RFC 7692 §7.2).
            if ($payload === false) {
               return $this->fail($Session, 1007);
            }
            // ? Post-inflate size cap — decompression-bomb guard.
            if (strlen($payload) > $Session->Client->maxMessageSize) {
               return $this->fail($Session, 1009);
            }
            // ? Compressed text is validated here, on the inflated plaintext.
            if ($messageOpcode === WS::OPCODE_TEXT && preg_match('//u', $payload) !== 1) {
               return $this->fail($Session, 1007);
            }
         }
         else {
            $payload = $Session->reassembly;
            // ? Non-compressed text was validated incrementally; a leftover
            //   partial sequence means the message ended mid-character (§8.1).
            if ($messageOpcode === WS::OPCODE_TEXT && $Session->utf8Pending !== '') {
               return $this->fail($Session, 1007);
            }
         }

         $Session->Message = new Message($messageOpcode, $payload);
         $Session->reassembly = '';
         $Session->reassemblyOpcode = 0;
         $Session->reassemblyCompressed = false;

         // : Complete message surfaced to the node.
         return ['consumed' => $Frame->consumed, 'message' => $Session->Message];
      }

      // : Intermediate fragment consumed; await the rest.
      return ['consumed' => $Frame->consumed];
   }

   /**
    * Handle a control frame (auto-pong, liveness, close echo).
    *
    * @return array{consumed: int, stop?: true}
    */
   private function handle (Session $Session, Frame $Frame): array
   {
      switch ($Frame->opcode) {
         case WS::OPCODE_PING:
            // @ Auto-pong with the same application data (§5.5.2/§5.5.3).
            $Session->deliver(Frame::encode(WS::OPCODE_PONG, $Frame->payload));
            return ['consumed' => $Frame->consumed];

         case WS::OPCODE_PONG:
            $Session->awaitingPong = false;
            return ['consumed' => $Frame->consumed];

         case WS::OPCODE_CLOSE:
            $length = strlen($Frame->payload);
            // ? A 1-byte close payload is illegal (§5.5.1).
            if ($length === 1) {
               return $this->fail($Session, 1002);
            }
            $code = 1000;
            if ($length >= 2) {
               $unpacked = unpack('n', substr($Frame->payload, 0, 2));
               $code = $unpacked !== false
                  ? (int) $unpacked[1]
                  : 1000;
               // ? Reserved / illegal close codes (§7.4.1).
               $valid = ($code >= 1000 && $code <= 1003)
                  || ($code >= 1007 && $code <= 1011)
                  || ($code >= 3000 && $code <= 4999);
               if ($valid === false) {
                  return $this->fail($Session, 1002);
               }
               // ? Close reason MUST be valid UTF-8 (§8.1).
               if (preg_match('//u', substr($Frame->payload, 2)) !== 1) {
                  return $this->fail($Session, 1007);
               }
            }
            // @ Echo the close code (§5.5.1) then tear the connection down — a
            //   graceful server close, so the client does not reconnect.
            $Session->closing = true;
            $Session->deliver(Frame::encode(WS::OPCODE_CLOSE, pack('n', $code)));
            $Session->disconnect();
            return ['consumed' => $Frame->consumed, 'stop' => true];
      }

      // : Unreachable — the caller already validated the control opcode.
      return ['consumed' => $Frame->consumed];
   }

   /**
    * Send a close frame for a protocol fault and tear the connection down.
    *
    * @return array{consumed: int, stop?: true}
    */
   private function fail (Session $Session, int $code): array
   {
      // @ A protocol fault is a won't-fix close — suppress client reconnect.
      $Session->closing = true;
      $Session->deliver(Frame::encode(WS::OPCODE_CLOSE, pack('n', $code)));
      $Session->disconnect();

      // : `consumed` is irrelevant — `stop` ends the node's decode loop.
      return ['consumed' => 0, 'stop' => true];
   }
}
