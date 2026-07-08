<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL\Drivers\MySQL;


use function count;
use function intdiv;
use function is_string;
use function ord;
use function sprintf;
use function strlen;
use function strpos;
use function substr;
use function unpack;
use InvalidArgumentException;

use Bootgly\ADI\Databases\SQL\Drivers\MySQL\Capabilities;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL\Message;


/**
 * Incremental MySQL wire packet decoder.
 *
 * Splits the byte stream into protocol packets (3-byte little-endian length
 * + sequence id + payload, coalescing 16 MB continuations) and parses packet
 * payloads through phase-specific read modes.
 */
class Decoder
{
   // # Column type codes (protocol::ColumnType)
   public const int TYPE_DECIMAL = 0;
   public const int TYPE_TINY = 1;
   public const int TYPE_SHORT = 2;
   public const int TYPE_LONG = 3;
   public const int TYPE_FLOAT = 4;
   public const int TYPE_DOUBLE = 5;
   public const int TYPE_NULL = 6;
   public const int TYPE_TIMESTAMP = 7;
   public const int TYPE_LONGLONG = 8;
   public const int TYPE_INT24 = 9;
   public const int TYPE_DATE = 10;
   public const int TYPE_TIME = 11;
   public const int TYPE_DATETIME = 12;
   public const int TYPE_YEAR = 13;
   public const int TYPE_VARCHAR = 15;
   public const int TYPE_BIT = 16;
   public const int TYPE_JSON = 245;
   public const int TYPE_NEWDECIMAL = 246;
   public const int TYPE_ENUM = 247;
   public const int TYPE_SET = 248;
   public const int TYPE_TINY_BLOB = 249;
   public const int TYPE_MEDIUM_BLOB = 250;
   public const int TYPE_LONG_BLOB = 251;
   public const int TYPE_BLOB = 252;
   public const int TYPE_VAR_STRING = 253;
   public const int TYPE_STRING = 254;
   public const int TYPE_GEOMETRY = 255;

   // # Column definition flags
   public const int FLAG_UNSIGNED = 0x20;

   /** Maximum single-packet payload — larger payloads continue in the next packet. */
   public const int PAYLOAD_MAX = 0xFFFFFF;

   /** Reclaim consumed buffer head when offset exceeds this threshold. */
   private const int COMPACT_THRESHOLD = 16384;

   // * Config
   // ...

   // * Data
   public string $buffer = '';

   // * Metadata
   private int $offset = 0;
   /** Coalesced payload of a 16 MB packet continuation in progress. */
   private string $fragment = '';


   /**
    * Decode wire packets from an incremental byte stream.
    *
    * @return array<int,Message>
    */
   public function decode (string $bytes): array
   {
      if ($bytes !== '') {
         $this->buffer .= $bytes;
      }

      $buffer = $this->buffer;
      $offset = $this->offset;
      $size = strlen($buffer);
      $Messages = [];

      // @@
      while ($size - $offset >= 4) {
         // @ Inline 24-bit unsigned little-endian read + sequence byte.
         $length = ord($buffer[$offset])
                 | (ord($buffer[$offset + 1]) << 8)
                 | (ord($buffer[$offset + 2]) << 16);
         $sequence = ord($buffer[$offset + 3]);

         if ($size - $offset < $length + 4) {
            break;
         }

         $payload = substr($buffer, $offset + 4, $length);
         $offset += $length + 4;

         // ? 16 MB continuation — coalesce until a shorter packet closes it.
         if ($length === self::PAYLOAD_MAX) {
            $this->fragment .= $payload;

            continue;
         }

         if ($this->fragment !== '') {
            $payload = $this->fragment . $payload;
            $this->fragment = '';
         }

         $Messages[] = new Message($sequence, $payload);
      }

      // @ Compact the buffer only when the consumed prefix grows large.
      if ($offset >= self::COMPACT_THRESHOLD) {
         $this->buffer = substr($buffer, $offset);
         $this->offset = 0;
      }
      else {
         $this->offset = $offset;
      }

      return $Messages;
   }

   /**
    * Read structured fields from a packet payload.
    *
    * Modes: `greeting`, `ok`, `error`, `eof`, `column`, `prepared`, `row`
    * (text protocol) and `binary` (binary protocol row). Row modes require
    * the column metadata of the current result set in `$columns`.
    *
    * @param array<int,array<string,int|string>> $columns
    *
    * @return array<int|string,mixed>
    */
   public function read (string $payload, string $mode, array $columns = []): array
   {
      return match ($mode) {
         'greeting' => $this->greet($payload),
         'ok' => $this->confirm($payload),
         'error' => $this->report($payload),
         'eof' => [
            'warnings' => $this->unpack($payload, 1, 2),
            'status' => $this->unpack($payload, 3, 2),
         ],
         'column' => $this->describe($payload),
         'prepared' => [
            'statement' => $this->unpack($payload, 1, 4),
            'columns' => $this->unpack($payload, 5, 2),
            'parameters' => $this->unpack($payload, 7, 2),
         ],
         'row' => $this->fetch($payload, $columns),
         'binary' => $this->extract($payload, $columns),
         default => throw new InvalidArgumentException("MySQL decoder mode is not supported: {$mode}."),
      };
   }

   /**
    * Parse the initial handshake (greeting) packet.
    *
    * @return array<string,int|string>
    */
   private function greet (string $payload): array
   {
      $protocol = ord($payload[0] ?? "\0");

      if ($protocol !== 10) {
         throw new InvalidArgumentException("MySQL handshake protocol is not supported: {$protocol}.");
      }

      $stop = strpos($payload, "\0", 1);

      if ($stop === false) {
         throw new InvalidArgumentException('MySQL greeting is malformed.');
      }

      $version = substr($payload, 1, $stop - 1);
      $cursor = $stop + 1;
      $thread = $this->unpack($payload, $cursor, 4);
      $cursor += 4;
      // ! Scramble part 1 — 8 bytes + 1 filler byte
      $nonce = substr($payload, $cursor, 8);
      $cursor += 9;
      $capabilities = $this->unpack($payload, $cursor, 2);
      $cursor += 2;
      $charset = ord($payload[$cursor] ?? "\0");
      $cursor += 1;
      $status = $this->unpack($payload, $cursor, 2);
      $cursor += 2;
      $capabilities |= $this->unpack($payload, $cursor, 2) << 16;
      $cursor += 2;
      $reserved = ord($payload[$cursor] ?? "\0");
      $cursor += 1 + 10;

      // ? Scramble part 2 — max(13, length - 8) bytes, trailing NUL stripped
      if ($capabilities & Capabilities::SECURE_CONNECTION) {
         $length = $reserved > 21 ? $reserved - 9 : 12;
         $nonce .= substr($payload, $cursor, $length);
         $cursor += $length + 1;
      }

      $plugin = '';

      if ($capabilities & Capabilities::PLUGIN_AUTH) {
         $stop = strpos($payload, "\0", $cursor);
         $plugin = $stop === false
            ? substr($payload, $cursor)
            : substr($payload, $cursor, $stop - $cursor);
      }

      // :
      return [
         'protocol' => $protocol,
         'version' => $version,
         'thread' => $thread,
         'nonce' => $nonce,
         'charset' => $charset,
         'status' => $status,
         'capabilities' => $capabilities,
         'plugin' => $plugin,
      ];
   }

   /**
    * Parse an OK packet.
    *
    * @return array<string,int>
    */
   private function confirm (string $payload): array
   {
      $cursor = 1;
      $affected = $this->slice($payload, $cursor);
      $inserted = $this->slice($payload, $cursor);

      // :
      return [
         'affected' => (int) $affected,
         'inserted' => (int) $inserted,
         'status' => $this->unpack($payload, $cursor, 2),
         'warnings' => $this->unpack($payload, $cursor + 2, 2),
      ];
   }

   /**
    * Parse an ERR packet.
    *
    * @return array<string,int|string>
    */
   private function report (string $payload): array
   {
      $code = $this->unpack($payload, 1, 2);
      $cursor = 3;
      $state = '';

      // ? Protocol 4.1 — `#` marker + 5-byte SQL state
      if (($payload[$cursor] ?? '') === '#') {
         $state = substr($payload, $cursor + 1, 5);
         $cursor += 6;
      }

      // :
      return [
         'code' => $code,
         'state' => $state,
         'message' => substr($payload, $cursor),
      ];
   }

   /**
    * Parse a column definition (protocol 4.1) packet.
    *
    * @return array<string,int|string>
    */
   private function describe (string $payload): array
   {
      $cursor = 0;
      $this->skip($payload, $cursor); // catalog
      $this->skip($payload, $cursor); // schema
      $this->skip($payload, $cursor); // table
      $this->skip($payload, $cursor); // original table
      $name = (string) $this->slice($payload, $cursor, true);
      $this->skip($payload, $cursor); // original name
      $cursor += 1; // fixed-length fields marker (0x0C)
      $cursor += 2; // character set
      $length = $this->unpack($payload, $cursor, 4);
      $cursor += 4;
      $type = ord($payload[$cursor] ?? "\0");
      $cursor += 1;
      $flags = $this->unpack($payload, $cursor, 2);

      // :
      return [
         'name' => $name,
         'length' => $length,
         'type' => $type,
         'flags' => $flags,
      ];
   }

   /**
    * Read one text-protocol row into positional values.
    *
    * @param array<int,array<string,int|string>> $columns
    *
    * @return array<int,null|string>
    */
   private function fetch (string $payload, array $columns): array
   {
      $Fields = [];
      $cursor = 0;
      $count = count($columns);

      // @@
      for ($index = 0; $index < $count; $index++) {
         // ? NULL is encoded as 0xFB
         if (($payload[$cursor] ?? '') === "\xFB") {
            $Fields[] = null;
            $cursor++;

            continue;
         }

         $value = $this->slice($payload, $cursor, true);
         $Fields[] = is_string($value) ? $value : null;
      }

      // :
      return $Fields;
   }

   /**
    * Read one binary-protocol row into positional values.
    *
    * @param array<int,array<string,int|string>> $columns
    *
    * @return array<int,mixed>
    */
   private function extract (string $payload, array $columns): array
   {
      $count = count($columns);
      // ! NULL bitmap — offset 2, after the 0x00 packet header
      $bytes = intdiv($count + 9, 8);
      $bitmap = substr($payload, 1, $bytes);
      $cursor = 1 + $bytes;
      $Fields = [];

      // @@
      for ($index = 0; $index < $count; $index++) {
         $bit = $index + 2;

         if ((ord($bitmap[intdiv($bit, 8)] ?? "\0") >> ($bit % 8)) & 1) {
            $Fields[] = null;

            continue;
         }

         $type = (int) ($columns[$index]['type'] ?? self::TYPE_VAR_STRING);
         $flags = (int) ($columns[$index]['flags'] ?? 0);
         $Fields[] = $this->value($payload, $cursor, $type, $flags);
      }

      // :
      return $Fields;
   }

   /**
    * Read one binary-protocol value at the cursor.
    */
   private function value (string $payload, int &$cursor, int $type, int $flags): mixed
   {
      $unsigned = ($flags & self::FLAG_UNSIGNED) !== 0;

      switch ($type) {
         case self::TYPE_TINY:
            $value = ord($payload[$cursor] ?? "\0");
            $cursor += 1;

            // :
            return $unsigned || $value < 0x80 ? $value : $value - 0x100;

         case self::TYPE_SHORT:
         case self::TYPE_YEAR:
            $value = $this->unpack($payload, $cursor, 2);
            $cursor += 2;

            // :
            return $unsigned || $value < 0x8000 ? $value : $value - 0x10000;

         case self::TYPE_LONG:
         case self::TYPE_INT24:
            $value = $this->unpack($payload, $cursor, 4);
            $cursor += 4;

            // :
            return $unsigned || $value < 0x80000000 ? $value : $value - 0x100000000;

         case self::TYPE_LONGLONG:
            $raw = substr($payload, $cursor, 8);
            $cursor += 8;
            /** @var array{1:int} $parts */
            $parts = unpack('P', $raw);
            $value = $parts[1];

            // ?: Unsigned values beyond PHP_INT_MAX wrap negative — keep them
            //    as exact decimal strings (`%u` reads the raw bits unsigned).
            if ($unsigned && $value < 0) {
               return sprintf('%u', $value);
            }

            // :
            return $value;

         case self::TYPE_FLOAT:
            /** @var array{1:float} $parts */
            $parts = unpack('g', substr($payload, $cursor, 4));
            $cursor += 4;

            // :
            return $parts[1];

         case self::TYPE_DOUBLE:
            /** @var array{1:float} $parts */
            $parts = unpack('e', substr($payload, $cursor, 8));
            $cursor += 8;

            // :
            return $parts[1];

         case self::TYPE_DATE:
         case self::TYPE_DATETIME:
         case self::TYPE_TIMESTAMP:
            // :
            return $this->stamp($payload, $cursor);

         case self::TYPE_TIME:
            // :
            return $this->clock($payload, $cursor);

         default:
            // :
            return $this->slice($payload, $cursor, true);
      }
   }

   /**
    * Read one binary date/datetime/timestamp value.
    */
   private function stamp (string $payload, int &$cursor): string
   {
      $length = ord($payload[$cursor] ?? "\0");
      $cursor += 1;

      // ? Zero length encodes the zero date
      if ($length === 0) {
         return '0000-00-00 00:00:00';
      }

      $year = $this->unpack($payload, $cursor, 2);
      $month = ord($payload[$cursor + 2] ?? "\0");
      $day = ord($payload[$cursor + 3] ?? "\0");
      $value = sprintf('%04d-%02d-%02d', $year, $month, $day);

      if ($length >= 7) {
         $hour = ord($payload[$cursor + 4] ?? "\0");
         $minute = ord($payload[$cursor + 5] ?? "\0");
         $second = ord($payload[$cursor + 6] ?? "\0");
         $value .= sprintf(' %02d:%02d:%02d', $hour, $minute, $second);
      }

      if ($length >= 11) {
         $micro = $this->unpack($payload, $cursor + 7, 4);
         $value .= sprintf('.%06d', $micro);
      }

      $cursor += $length;

      // :
      return $value;
   }

   /**
    * Read one binary time (duration) value.
    */
   private function clock (string $payload, int &$cursor): string
   {
      $length = ord($payload[$cursor] ?? "\0");
      $cursor += 1;

      // ?
      if ($length === 0) {
         return '00:00:00';
      }

      $negative = ord($payload[$cursor] ?? "\0") === 1;
      $days = $this->unpack($payload, $cursor + 1, 4);
      $hours = ord($payload[$cursor + 5] ?? "\0") + ($days * 24);
      $minutes = ord($payload[$cursor + 6] ?? "\0");
      $seconds = ord($payload[$cursor + 7] ?? "\0");
      $value = sprintf('%s%02d:%02d:%02d', $negative ? '-' : '', $hours, $minutes, $seconds);

      if ($length >= 12) {
         $micro = $this->unpack($payload, $cursor + 8, 4);
         $value .= sprintf('.%06d', $micro);
      }

      $cursor += $length;

      // :
      return $value;
   }

   /**
    * Read one length-encoded string at the cursor.
    */
   public function slice (string $payload, int &$cursor, bool $string = false): null|int|string
   {
      $first = ord($payload[$cursor] ?? "\0");

      // ? 0xFB encodes NULL in length-encoded integer position
      if ($first === 0xFB) {
         $cursor += 1;

         return null;
      }

      if ($first < 0xFB) {
         $cursor += 1;
         $length = $first;
      }
      elseif ($first === 0xFC) {
         $length = $this->unpack($payload, $cursor + 1, 2);
         $cursor += 3;
      }
      elseif ($first === 0xFD) {
         $length = $this->unpack($payload, $cursor + 1, 3);
         $cursor += 4;
      }
      else {
         $length = $this->unpack($payload, $cursor + 1, 8);
         $cursor += 9;
      }

      // ?: Length-encoded integer position — return the integer itself
      if ($string === false) {
         return $length;
      }

      $value = substr($payload, $cursor, $length);
      $cursor += $length;

      // :
      return $value;
   }

   /**
    * Skip one length-encoded string at the cursor.
    */
   private function skip (string $payload, int &$cursor): void
   {
      $this->slice($payload, $cursor, true);
   }

   /**
    * Unpack an unsigned little-endian integer from a payload offset.
    */
   public function unpack (string $payload, int $offset, int $width): int
   {
      $size = strlen($payload);
      $value = 0;

      // @@
      for ($index = 0; $index < $width; $index++) {
         if ($offset + $index >= $size) {
            break;
         }

         $value |= ord($payload[$offset + $index]) << ($index * 8);
      }

      // :
      return $value;
   }
}
