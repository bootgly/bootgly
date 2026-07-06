<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\WS_Server_CLI\Decoders;


use function pack;
use function preg_match;
use function strlen;
use function substr;
use function time;
use function unpack;

use Bootgly\WPI\Endpoints\Servers\Decoder\States;
use Bootgly\WPI\Endpoints\Servers\Packages;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCP_Packages;
use Bootgly\WPI\Modules\WS;
use Bootgly\WPI\Nodes\WS_Server_CLI\Decoders;
use Bootgly\WPI\Nodes\WS_Server_CLI\Message;
use Bootgly\WPI\Nodes\WS_Server_CLI\Message\Frame;
use Bootgly\WPI\Nodes\WS_Server_CLI\Message\UTF8;
use Bootgly\WPI\Nodes\WS_Server_CLI\Session;


class Decoder_Framing extends Decoders
{
   public function decode (Packages $Package, string $buffer, int $size): States
   {
      /** @var TCP_Packages $Package */
      $Session = $Package->decoded;
      if ($Session instanceof Session === false) {
         return States::Rejected;
      }
      $Session->lastActivity = time();

      // @ Prepend carried partial-frame bytes from a previous read. `carry` is
      //   only non-empty on the first decode of a read; the pipeline re-calls
      //   (offset > 0) see it empty, so `consumed` stays in `$buffer` space.
      $data = "{$Session->carry}{$buffer}";
      $carryBefore = strlen($Session->carry);
      $Session->carry = '';

      // @ Decode a single frame.
      $Frame = Frame::decode($data, 0, Session::$maxFrameSize);

      // ? Partial frame — buffer and wait.
      if ($Frame === null) {
         $Session->carry = $data;
         $Package->consumed = $size;
         return States::Incomplete;
      }

      // ? Fatal framing fault detected from the header (oversize / bad length).
      if ($Frame->error !== 0) {
         return $this->fail($Package, $Session, $Frame->error);
      }

      // ? RSV2/RSV3 must always be clear. RSV1 marks a permessage-deflate
      //   compressed message and is valid only on the first data frame when
      //   the extension was negotiated.
      if ($Frame->rsv2 !== 0 || $Frame->rsv3 !== 0) {
         return $this->fail($Package, $Session, 1002);
      }
      if (
         $Frame->rsv1 !== 0
         && (
            $Session->Inflator === null
            || $Frame->opcode === WS::OPCODE_CONTINUATION
            || $Frame->opcode >= WS::OPCODE_CLOSE
         )
      ) {
         return $this->fail($Package, $Session, 1002);
      }

      // ? Client-to-server frames MUST be masked (§5.1).
      if ($Frame->masked === false) {
         return $this->fail($Package, $Session, 1002);
      }

      $opcode = $Frame->opcode;

      // # Control frames (close / ping / pong): <=125 bytes, never fragmented.
      if ($opcode >= WS::OPCODE_CLOSE) {
         if (
            $opcode !== WS::OPCODE_CLOSE
            && $opcode !== WS::OPCODE_PING
            && $opcode !== WS::OPCODE_PONG
         ) {
            return $this->fail($Package, $Session, 1002);
         }
         if ($Frame->length > 125 || $Frame->fin === false) {
            return $this->fail($Package, $Session, 1002);
         }

         $fault = $this->handle($Package, $Session, $Frame);
         if ($fault !== 0) {
            return $this->fail($Package, $Session, $fault);
         }

         $Package->consumed = $Frame->consumed - $carryBefore;
         return States::Complete;
      }

      // # Data frames (continuation / text / binary)
      if ($opcode === WS::OPCODE_CONTINUATION) {
         // ? Continuation without an in-progress message.
         if ($Session->reassemblyOpcode === 0) {
            return $this->fail($Package, $Session, 1002);
         }
         $Session->reassembly .= $Frame->payload;
      }
      else if ($opcode === WS::OPCODE_TEXT || $opcode === WS::OPCODE_BINARY) {
         // ? New data frame while a fragmented message is still open.
         if ($Session->reassemblyOpcode !== 0) {
            return $this->fail($Package, $Session, 1002);
         }
         $Session->reassemblyOpcode = $opcode;
         $Session->reassemblyCompressed = $Frame->rsv1 !== 0;
         $Session->reassembly = $Frame->payload;
         $Session->utf8Pending = '';
      }
      else {
         // ? Reserved data opcode (0x3..0x7).
         return $this->fail($Package, $Session, 1002);
      }

      // ? Cumulative message-size cap.
      if (strlen($Session->reassembly) > Session::$maxMessageSize) {
         return $this->fail($Package, $Session, 1009);
      }

      // ? Incremental UTF-8 validation (fail-fast) for non-compressed text:
      //   validate THIS fragment's bytes as they arrive, so an invalid sequence
      //   closes 1007 mid-stream — not only after the final fragment (§8.1,
      //   Autobahn 6.4.*). Compressed text is validated post-inflate at FIN.
      if (
         $Session->reassemblyOpcode === WS::OPCODE_TEXT
         && $Session->reassemblyCompressed === false
      ) {
         $pending = UTF8::validate($Session->utf8Pending, $Frame->payload);
         if ($pending === null) {
            return $this->fail($Package, $Session, 1007);
         }
         $Session->utf8Pending = $pending;
      }

      // ? Final fragment — surface a complete message to the encoder.
      if ($Frame->fin) {
         $messageOpcode = $Session->reassemblyOpcode;

         if ($Session->reassemblyCompressed) {
            $payload = $Session->inflate($Session->reassembly);
            // ? Invalid compressed data (RFC 7692 §7.2).
            if ($payload === false) {
               return $this->fail($Package, $Session, 1007);
            }
            // ? Post-inflate size cap — decompression-bomb guard.
            if (strlen($payload) > Session::$maxMessageSize) {
               return $this->fail($Package, $Session, 1009);
            }
            // ? Compressed text is validated here, on the inflated plaintext —
            //   it is only available after inflation at message end (§8.1).
            if ($messageOpcode === WS::OPCODE_TEXT && preg_match('//u', $payload) !== 1) {
               return $this->fail($Package, $Session, 1007);
            }
         }
         else {
            $payload = $Session->reassembly;
            // ? Non-compressed text was validated incrementally per fragment;
            //   a leftover partial sequence means the message ended mid-character
            //   (§8.1).
            if ($messageOpcode === WS::OPCODE_TEXT && $Session->utf8Pending !== '') {
               return $this->fail($Package, $Session, 1007);
            }
         }

         $Session->Message = new Message($messageOpcode, $payload);
         $Session->reassembly = '';
         $Session->reassemblyOpcode = 0;
         $Session->reassemblyCompressed = false;
      }

      // :
      $Package->consumed = $Frame->consumed - $carryBefore;
      return States::Complete;
   }

   /**
    * Handle a control frame internally (auto-pong, liveness, close echo).
    *
    * @return int `0` on success, or a close code to fail the connection with.
    */
   private function handle (TCP_Packages $Package, Session $Session, Frame $Frame): int
   {
      switch ($Frame->opcode) {
         case WS::OPCODE_PING:
            // @ Auto-pong with the same application data (§5.5.2/§5.5.3).
            $Session->outbox .= Frame::encode(WS::OPCODE_PONG, $Frame->payload);
            return 0;

         case WS::OPCODE_PONG:
            $Session->awaitingPong = false;
            return 0;

         case WS::OPCODE_CLOSE:
            $length = strlen($Frame->payload);
            // ? A 1-byte close payload is illegal (§5.5.1).
            if ($length === 1) {
               return 1002;
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
                  return 1002;
               }
               // ? Close reason MUST be valid UTF-8 (§8.1).
               if (preg_match('//u', substr($Frame->payload, 2)) !== 1) {
                  return 1007;
               }
            }
            // @ Echo the close code (§5.5.1) then close after writing it.
            $Session->outbox .= Frame::encode(WS::OPCODE_CLOSE, pack('n', $code));
            $Package->closeAfterWrite = true;
            $Session->disconnect();
            return 0;
      }

      // : Unreachable — the caller already validated the control opcode.
      return 0;
   }

   /**
    * Queue a close frame for a protocol fault and tear the connection down.
    */
   private function fail (TCP_Packages $Package, Session $Session, int $code): States
   {
      $Session->outbox .= Frame::encode(WS::OPCODE_CLOSE, pack('n', $code));
      $Package->closeAfterWrite = true;
      $Session->disconnect();

      // : Complete so the encoder flushes the close; consumed is irrelevant
      //   because `closeAfterWrite` short-circuits pipelining.
      $Package->consumed = 0;
      return States::Complete;
   }
}
