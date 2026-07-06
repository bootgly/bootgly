<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Modules\HTTP2;


use function chr;
use function count;
use function ord;
use function strlen;
use function substr;

use Bootgly\WPI\Modules\HTTP2\HPACK\Huffman;


/**
 * HPACK header compression codec (RFC 7541).
 *
 * Decoding is complete: indexed fields, all literal forms, dynamic table
 * with eviction, table-size updates and Huffman-coded string literals.
 *
 * Encoding is deliberately context-free: indexed / literal-without-indexing
 * against the static table only — no dynamic-table insertions and no Huffman.
 * Encoded blocks therefore do not depend on connection state and can be
 * cached and replayed across streams and connections.
 *
 * One instance per connection carries the request-decoding dynamic table.
 */
class HPACK
{
   // @ Static table (RFC 7541 Appendix A), index 1..61.
   protected const array TABLE = [
      [':authority', ''],
      [':method', 'GET'],
      [':method', 'POST'],
      [':path', '/'],
      [':path', '/index.html'],
      [':scheme', 'http'],
      [':scheme', 'https'],
      [':status', '200'],
      [':status', '204'],
      [':status', '206'],
      [':status', '304'],
      [':status', '400'],
      [':status', '404'],
      [':status', '500'],
      ['accept-charset', ''],
      ['accept-encoding', 'gzip, deflate'],
      ['accept-language', ''],
      ['accept-ranges', ''],
      ['accept', ''],
      ['access-control-allow-origin', ''],
      ['age', ''],
      ['allow', ''],
      ['authorization', ''],
      ['cache-control', ''],
      ['content-disposition', ''],
      ['content-encoding', ''],
      ['content-language', ''],
      ['content-length', ''],
      ['content-location', ''],
      ['content-range', ''],
      ['content-type', ''],
      ['cookie', ''],
      ['date', ''],
      ['etag', ''],
      ['expect', ''],
      ['expires', ''],
      ['from', ''],
      ['host', ''],
      ['if-match', ''],
      ['if-modified-since', ''],
      ['if-none-match', ''],
      ['if-range', ''],
      ['if-unmodified-since', ''],
      ['last-modified', ''],
      ['link', ''],
      ['location', ''],
      ['max-forwards', ''],
      ['proxy-authenticate', ''],
      ['proxy-authorization', ''],
      ['range', ''],
      ['referer', ''],
      ['refresh', ''],
      ['retry-after', ''],
      ['server', ''],
      ['set-cookie', ''],
      ['strict-transport-security', ''],
      ['transfer-encoding', ''],
      ['user-agent', ''],
      ['vary', ''],
      ['via', ''],
      ['www-authenticate', '']
   ];
   // @ Full-match map ("name\x00value" → static index) for encoding.
   //   NUL is written as \x00 — a bare \0 before a digit would parse as an
   //   octal escape (e.g. "\0200" is chr(16) . '0', not NUL . '200').
   protected const array PAIRS = [
      ":method\x00GET" => 2,
      ":method\x00POST" => 3,
      ":path\x00/" => 4,
      ":path\x00/index.html" => 5,
      ":scheme\x00http" => 6,
      ":scheme\x00https" => 7,
      ":status\x00200" => 8,
      ":status\x00204" => 9,
      ":status\x00206" => 10,
      ":status\x00304" => 11,
      ":status\x00400" => 12,
      ":status\x00404" => 13,
      ":status\x00500" => 14,
      "accept-encoding\x00gzip, deflate" => 16
   ];
   // @ Name-match map (name → first static index) for encoding.
   protected const array NAMES = [
      ':authority' => 1,
      ':method' => 2,
      ':path' => 4,
      ':scheme' => 6,
      ':status' => 8,
      'accept-charset' => 15,
      'accept-encoding' => 16,
      'accept-language' => 17,
      'accept-ranges' => 18,
      'accept' => 19,
      'access-control-allow-origin' => 20,
      'age' => 21,
      'allow' => 22,
      'authorization' => 23,
      'cache-control' => 24,
      'content-disposition' => 25,
      'content-encoding' => 26,
      'content-language' => 27,
      'content-length' => 28,
      'content-location' => 29,
      'content-range' => 30,
      'content-type' => 31,
      'cookie' => 32,
      'date' => 33,
      'etag' => 34,
      'expect' => 35,
      'expires' => 36,
      'from' => 37,
      'host' => 38,
      'if-match' => 39,
      'if-modified-since' => 40,
      'if-none-match' => 41,
      'if-range' => 42,
      'if-unmodified-since' => 43,
      'last-modified' => 44,
      'link' => 45,
      'location' => 46,
      'max-forwards' => 47,
      'proxy-authenticate' => 48,
      'proxy-authorization' => 49,
      'range' => 50,
      'referer' => 51,
      'refresh' => 52,
      'retry-after' => 53,
      'server' => 54,
      'set-cookie' => 55,
      'strict-transport-security' => 56,
      'transfer-encoding' => 57,
      'user-agent' => 58,
      'vary' => 59,
      'via' => 60,
      'www-authenticate' => 61
   ];

   // * Config
   // @ Hard ceiling for the dynamic table (the HEADER_TABLE_SIZE we advertise);
   //   a table-size update above it is a compression error (RFC 7541 §4.2).
   public int $limit;

   // * Data
   // # Dynamic table (decode side) — absolute insertion id → [name, value, size]
   /** @var array<int, array{0: string, 1: string, 2: int}> */
   protected array $dynamic;

   // * Metadata
   // # Current effective maximum (≤ $limit; peer can lower it in-block)
   protected int $maximum;
   // # Current table octet size (entry sizes include the 32-octet overhead)
   protected int $size;
   // # Absolute insertion counter (newest entry id)
   protected int $inserted;


   public function __construct (int $limit = 4096)
   {
      // * Config
      $this->limit = $limit;

      // * Data
      $this->dynamic = [];

      // * Metadata
      $this->maximum = $limit;
      $this->size = 0;
      $this->inserted = 0;
   }

   /**
    * Decode a complete header block into ordered [name, value] pairs.
    *
    * @param string $block Concatenated HEADERS(+CONTINUATION) fragments.
    * @param int $max Decoded header-list octet cap (name + value + 32 each).
    *
    * @return null|array<int, array{0: string, 1: string}> `null` on compression error.
    */
   public function decode (string $block, int $max): null|array
   {
      // !
      $fields = [];
      $offset = 0;
      $length = strlen($block);
      $total = 0;
      $begun = false;

      // @@
      while ($offset < $length) {
         $byte = ord($block[$offset]);

         if ($byte >= 0x80) {
            // # Indexed field (RFC 7541 §6.1)
            $index = self::read($block, $length, $offset, 7);
            if ($index === null || $index === 0) {
               return null;
            }
            $entry = $this->fetch($index);
            if ($entry === null) {
               return null;
            }
            [$name, $value] = $entry;
         }
         else if ($byte >= 0x40) {
            // # Literal with incremental indexing (RFC 7541 §6.2.1)
            $index = self::read($block, $length, $offset, 6);
            if ($index === null) {
               return null;
            }
            if ($index === 0) {
               $name = self::extract($block, $length, $offset);
            }
            else {
               $name = $this->fetch($index)[0] ?? null;
            }
            if ($name === null) {
               return null;
            }
            $value = self::extract($block, $length, $offset);
            if ($value === null) {
               return null;
            }
            $this->insert($name, $value);
         }
         else if ($byte >= 0x20) {
            // # Dynamic table size update (RFC 7541 §6.3)
            // ? Only allowed before the first field of a block (RFC 7541 §4.2)
            if ($begun) {
               return null;
            }
            $maximum = self::read($block, $length, $offset, 5);
            if ($maximum === null || $maximum > $this->limit) {
               return null;
            }
            $this->resize($maximum);
            continue;
         }
         else {
            // # Literal without indexing / never indexed (RFC 7541 §6.2.2/§6.2.3)
            $index = self::read($block, $length, $offset, 4);
            if ($index === null) {
               return null;
            }
            if ($index === 0) {
               $name = self::extract($block, $length, $offset);
            }
            else {
               $name = $this->fetch($index)[0] ?? null;
            }
            if ($name === null) {
               return null;
            }
            $value = self::extract($block, $length, $offset);
            if ($value === null) {
               return null;
            }
         }

         // ? Enforce the decoded header-list cap (SETTINGS_MAX_HEADER_LIST_SIZE)
         $total += strlen($name) + strlen($value) + 32;
         if ($total > $max) {
            return null;
         }

         $begun = true;
         $fields[] = [$name, $value];
      }

      // :
      return $fields;
   }

   /**
    * Apply a dynamic table size update: set the effective maximum and evict.
    */
   public function resize (int $maximum): void
   {
      $this->maximum = $maximum;

      // @ Evict oldest entries until the table fits
      $oldest = $this->inserted - count($this->dynamic) + 1;
      while ($this->size > $maximum) {
         $this->size -= $this->dynamic[$oldest][2];
         unset($this->dynamic[$oldest]);
         $oldest++;
      }
   }

   /**
    * Encode ordered [name, value] pairs as a context-free header block.
    *
    * Names must already be lowercase. Indexed representation for full static
    * matches; literal-without-indexing otherwise. No dynamic-table state is
    * created or referenced, so the output is cacheable and replayable.
    *
    * @param array<int, array{0: string, 1: string}> $fields
    */
   public static function encode (array $fields): string
   {
      // !
      $block = '';

      // @@
      foreach ($fields as [$name, $value]) {
         // ? Full static match → indexed field (single byte)
         $index = self::PAIRS["$name\x00$value"] ?? null;
         if ($index !== null) {
            $block .= chr(0x80 | $index);
            continue;
         }

         // ?: Name-only static match → literal without indexing, indexed name
         $index = self::NAMES[$name] ?? null;
         if ($index !== null) {
            $block .= self::write($index, 4, 0x00);
         }
         else {
            $block .= "\x00" . self::write(strlen($name), 7, 0x00) . $name;
         }

         $block .= self::write(strlen($value), 7, 0x00) . $value;
      }

      // :
      return $block;
   }

   /**
    * Fetch an entry by HPACK index: 1..61 static, 62.. dynamic (newest first).
    *
    * @return null|array{0: string, 1: string}|array{0: string, 1: string, 2: int}
    */
   protected function fetch (int $index): null|array
   {
      // ?: Static table
      if ($index <= 61) {
         return self::TABLE[$index - 1];
      }

      // : Dynamic table — relative index 1 is the newest insertion
      return $this->dynamic[$this->inserted - ($index - 61) + 1] ?? null;
   }

   /**
    * Insert an entry into the dynamic table, evicting to fit (RFC 7541 §4.4).
    */
   protected function insert (string $name, string $value): void
   {
      $size = strlen($name) + strlen($value) + 32;

      // ? An entry larger than the table maximum empties the table
      if ($size > $this->maximum) {
         $this->dynamic = [];
         $this->size = 0;
         return;
      }

      // @ Evict oldest entries until the new entry fits
      $oldest = $this->inserted - count($this->dynamic) + 1;
      while ($this->size + $size > $this->maximum) {
         $this->size -= $this->dynamic[$oldest][2];
         unset($this->dynamic[$oldest]);
         $oldest++;
      }

      $this->dynamic[++$this->inserted] = [$name, $value, $size];
      $this->size += $size;
   }

   /**
    * Decode a prefixed integer (RFC 7541 §5.1), advancing `$offset`.
    *
    * @return null|int `null` on truncation or overflow.
    */
   protected static function read (string $block, int $length, int &$offset, int $prefix): null|int
   {
      $mask = (1 << $prefix) - 1;
      $value = ord($block[$offset++]) & $mask;

      // ?: Fits in the prefix
      if ($value < $mask) {
         return $value;
      }

      // @@ Continuation octets (7 bits each, little-endian groups)
      $shift = 0;
      do {
         // ? Truncated block
         if ($offset >= $length) {
            return null;
         }
         // ? Overflow guard (values above 2^28 have no legitimate use here)
         if ($shift > 28) {
            return null;
         }

         $byte = ord($block[$offset++]);
         $value += ($byte & 0x7f) << $shift;
         $shift += 7;
      }
      while (($byte & 0x80) !== 0);

      // :
      return $value;
   }

   /**
    * Decode a string literal (RFC 7541 §5.2), advancing `$offset`.
    *
    * @return null|string `null` on truncation or invalid Huffman coding.
    */
   protected static function extract (string $block, int $length, int &$offset): null|string
   {
      // ? Truncated length octet
      if ($offset >= $length) {
         return null;
      }

      $huffman = (ord($block[$offset]) & 0x80) !== 0;
      $size = self::read($block, $length, $offset, 7);

      // ? Truncated payload
      if ($size === null || $offset + $size > $length) {
         return null;
      }

      $string = substr($block, $offset, $size);
      $offset += $size;

      // ?:
      if ($huffman) {
         return Huffman::decode($string);
      }

      // :
      return $string;
   }

   /**
    * Encode a prefixed integer (RFC 7541 §5.1).
    */
   protected static function write (int $value, int $prefix, int $first): string
   {
      $mask = (1 << $prefix) - 1;

      // ?: Fits in the prefix
      if ($value < $mask) {
         return chr($first | $value);
      }

      // @ Continuation octets
      $output = chr($first | $mask);
      $value -= $mask;
      while ($value >= 0x80) {
         $output .= chr(($value & 0x7f) | 0x80);
         $value >>= 7;
      }
      $output .= chr($value);

      // :
      return $output;
   }
}
