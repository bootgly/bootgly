<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\WS_Client_CLI\Message;


use function chr;
use function intdiv;
use function ord;
use function pack;
use function random_bytes;
use function str_repeat;
use function strlen;
use function substr;
use function unpack;


/**
 * A single RFC 6455 frame (§5.2) and its byte codec, client side.
 *
 * Decoding unmasks any masked frame (a conformant server never masks, but the
 * framing decoder enforces that). Encoding masks every client frame: a random
 * 4-byte key is prefixed and the payload XOR-ed (§5.1/§5.3 — clients MUST mask).
 */
final class Frame
{
   // * Data
   public bool $fin = true;
   public int $rsv1 = 0;
   public int $rsv2 = 0;
   public int $rsv3 = 0;
   public int $opcode = 0;
   public bool $masked = false;
   public int $length = 0;
   public string $payload = '';

   // * Metadata
   public int $consumed = 0;     // total wire bytes of this frame
   public int $error = 0;        // 0 = ok; otherwise a close code (1002 / 1009)


   /**
    * Decode one frame from `$buffer` at `$offset`.
    *
    * @return null|self `null` when fewer than a full frame's bytes are present
    *   (caller buffers and waits). A frame with `error !== 0` signals a fatal
    *   framing fault the caller must close with.
    */
   public static function decode (string $buffer, int $offset, int $max): null|self
   {
      // ? Need the 2-byte minimum header.
      $available = strlen($buffer) - $offset;
      if ($available < 2) {
         return null;
      }

      $byte0 = ord($buffer[$offset]);
      $byte1 = ord($buffer[$offset + 1]);

      $masked = ($byte1 & 0x80) !== 0;
      $length = $byte1 & 0x7F;
      $headerLength = 2;

      // @ Extended payload length.
      if ($length === 126) {
         if ($available < 4) {
            return null;
         }
         $extended = unpack('n', substr($buffer, $offset + 2, 2));
         if ($extended === false) {
            return null;
         }
         $length = (int) $extended[1];
         // ? Non-minimal length encoding is a protocol error (§5.2).
         if ($length < 126) {
            return self::fault(1002);
         }
         $headerLength = 4;
      }
      else if ($length === 127) {
         if ($available < 10) {
            return null;
         }
         $high = unpack('N', substr($buffer, $offset + 2, 4));
         $low = unpack('N', substr($buffer, $offset + 6, 4));
         if ($high === false || $low === false) {
            return null;
         }
         // ? The most-significant bit of a 64-bit length MUST be 0 (§5.2).
         if (((int) $high[1] & 0x80000000) !== 0) {
            return self::fault(1002);
         }
         $length = ((int) $high[1] << 32) | (int) $low[1];
         // ? Non-minimal length encoding is a protocol error (§5.2).
         if ($length < 65536) {
            return self::fault(1002);
         }
         $headerLength = 10;
      }

      // ? Oversize guard — reject before buffering the payload (DoS).
      if ($length > $max) {
         return self::fault(1009);
      }

      // ? Whole frame present?
      $maskLength = $masked ? 4 : 0;
      $required = $headerLength + $maskLength + $length;
      if ($available < $required) {
         return null;
      }

      // @ Payload (unmasked when a mask is present).
      $payload = (string) substr($buffer, $offset + $headerLength + $maskLength, $length);
      if ($masked) {
         $key = (string) substr($buffer, $offset + $headerLength, 4);
         $payload = self::mask($payload, $key);
      }

      // :
      $Frame = new self;
      $Frame->fin = ($byte0 & 0x80) !== 0;
      $Frame->rsv1 = $byte0 & 0x40;
      $Frame->rsv2 = $byte0 & 0x20;
      $Frame->rsv3 = $byte0 & 0x10;
      $Frame->opcode = $byte0 & 0x0F;
      $Frame->masked = $masked;
      $Frame->length = $length;
      $Frame->payload = $payload;
      $Frame->consumed = $required;

      return $Frame;
   }

   /**
    * Encode a client frame. Always masked (§5.3): a random 4-byte key is
    * prefixed and the payload XOR-ed against it.
    */
   public static function encode (int $opcode, string $payload, bool $fin = true, int $rsv1 = 0): string
   {
      $length = strlen($payload);

      $byte0 = ($fin ? 0x80 : 0x00) | ($rsv1 !== 0 ? 0x40 : 0x00) | ($opcode & 0x0F);
      $header = chr($byte0);

      // @ Length form (the mask bit is always set on a client frame).
      if ($length < 126) {
         $header .= chr(0x80 | $length);
      }
      else if ($length < 65536) {
         $header .= chr(0x80 | 126) . pack('n', $length);
      }
      else {
         $header .= chr(0x80 | 127) . pack('J', $length);
      }

      // @ A 4-byte key precedes the XOR'd payload (§5.3).
      $key = random_bytes(4);

      return $header . $key . self::mask($payload, $key);
   }

   private static function fault (int $code): self
   {
      $Frame = new self;
      $Frame->opcode = 0x8;
      $Frame->error = $code;

      return $Frame;
   }

   /**
    * XOR a payload against a 4-byte key (symmetric — masks and unmasks).
    */
   private static function mask (string $payload, string $key): string
   {
      $length = strlen($payload);
      if ($length === 0) {
         return '';
      }

      // @ XOR the payload against the 4-byte key repeated to length.
      $repeated = str_repeat($key, intdiv($length, 4) + 1);

      return $payload ^ substr($repeated, 0, $length);
   }
}
