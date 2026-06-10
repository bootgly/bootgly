<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Data\RESP;


use function is_int;
use function is_string;
use function strlen;
use function strpos;
use function substr;
use InvalidArgumentException;
use RuntimeException;


/**
 * Incremental RESP reply decoder (RESP2 + RESP3).
 *
 * Mirrors the buffer + offset cursor pattern used by the PostgreSQL decoder:
 * bytes accumulate, the cursor advances over fully-parsed replies, and the
 * consumed prefix is compacted only when it grows past a threshold to keep
 * substr() churn off the hot path. Incomplete trailing bytes are retained
 * until more arrive.
 *
 * Error replies (`-`, `!`) are decoded as RuntimeException instances (never
 * thrown) so the caller decides how to surface them — preserving position in
 * a pipelined stream.
 */
class Decoder
{
   private const int COMPACT_THRESHOLD = 16384;

   // * Data
   public string $buffer = '';

   // * Metadata
   private int $offset = 0;


   /**
    * Append bytes and return every reply now fully available.
    *
    * @return array<int,mixed>
    */
   public function decode (string $bytes = ''): array
   {
      if ($bytes !== '') {
         $this->buffer .= $bytes;
      }

      $replies = [];
      $size = strlen($this->buffer);

      while ($this->offset < $size) {
         $result = $this->parse($this->offset);

         // ? Incomplete reply — wait for more bytes
         if ($result === null) {
            break;
         }

         $this->offset = $result[1];
         $replies[] = $result[0];
      }

      // @ Compact the consumed prefix only once it grows large
      if ($this->offset >= self::COMPACT_THRESHOLD) {
         $this->buffer = substr($this->buffer, $this->offset);
         $this->offset = 0;
      }

      return $replies;
   }

   /**
    * Reset the decoder for reuse on a fresh connection.
    */
   public function reset (): void
   {
      $this->buffer = '';
      $this->offset = 0;
   }

   // ---

   /**
    * Parse one reply starting at $offset.
    *
    * @return null|array{0: mixed, 1: int} [value, next offset]; null if incomplete
    */
   private function parse (int $offset): null|array
   {
      $buffer = $this->buffer;
      $size = strlen($buffer);

      // ? Need at least the type byte
      if ($offset >= $size) {
         return null;
      }

      $type = $buffer[$offset];

      // @ Every reply header ends at the next CRLF
      $crlf = strpos($buffer, "\r\n", $offset + 1);
      if ($crlf === false) {
         return null;
      }

      $line = substr($buffer, $offset + 1, $crlf - $offset - 1);
      $next = $crlf + 2;

      switch ($type) {
         // # Simple string
         case '+':
            return [$line, $next];
         // # Error
         case '-':
         case '!':
            return [new RuntimeException($line), $next];
         // # Integer
         case ':':
            return [(int) $line, $next];
         // # Boolean (RESP3)
         case '#':
            return [$line === 't', $next];
         // # Double (RESP3)
         case ',':
            return [(float) $line, $next];
         // # Big number (RESP3) — kept as string to avoid overflow
         case '(':
            return [$line, $next];
         // # Null (RESP3)
         case '_':
            return [null, $next];
         // # Bulk string / verbatim string (RESP3)
         case '$':
         case '=':
            $length = (int) $line;
            // ? Null bulk
            if ($length < 0) {
               return [null, $next];
            }
            $end = $next + $length;
            // ? Need the payload plus its trailing CRLF
            if ($end + 2 > $size) {
               return null;
            }
            return [substr($buffer, $next, $length), $end + 2];
         // # Array / set / push (RESP3)
         case '*':
         case '~':
         case '>':
            $count = (int) $line;
            // ? Null array
            if ($count < 0) {
               return [null, $next];
            }
            $items = [];
            $cursor = $next;
            for ($i = 0; $i < $count; $i++) {
               $item = $this->parse($cursor);
               if ($item === null) {
                  return null;
               }
               $items[] = $item[0];
               $cursor = $item[1];
            }
            return [$items, $cursor];
         // # Map (RESP3)
         case '%':
            $count = (int) $line;
            $map = [];
            $cursor = $next;
            for ($i = 0; $i < $count; $i++) {
               $keyReply = $this->parse($cursor);
               if ($keyReply === null) {
                  return null;
               }
               $valueReply = $this->parse($keyReply[1]);
               if ($valueReply === null) {
                  return null;
               }

               $key = $keyReply[0];
               if (is_int($key) === true || is_string($key) === true) {
                  $map[$key] = $valueReply[0];
               }
               else {
                  $map[] = $valueReply[0];
               }

               $cursor = $valueReply[1];
            }
            return [$map, $cursor];
         default:
            throw new InvalidArgumentException("Unknown RESP type byte: {$type}");
      }
   }
}
