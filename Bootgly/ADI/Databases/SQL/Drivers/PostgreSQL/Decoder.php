<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL\Drivers\PostgreSQL;


use function ord;
use function strlen;
use function strpos;
use function substr;
use InvalidArgumentException;

use Bootgly\ADI\Databases\SQL\Drivers\PostgreSQL\Message;


/**
 * Incremental PostgreSQL backend message decoder.
 */
class Decoder
{
   private const array ERRORS = [
      'S' => 'severity',
      'V' => 'severity_localized',
      'C' => 'code',
      'M' => 'message',
      'D' => 'detail',
      'H' => 'hint',
      'P' => 'position',
      'W' => 'where',
      'F' => 'file',
      'L' => 'line',
      'R' => 'routine',
   ];

   // * Config
   // ...

   // * Data
   public string $buffer = '';

   // * Metadata
   private int $offset = 0;

   /** Reclaim consumed buffer head when offset exceeds this threshold. */
   private const int COMPACT_THRESHOLD = 16384;


   /**
    * Decode backend messages from an incremental byte stream.
    *
    * Uses an offset cursor instead of slicing the buffer on every consumed
    * message. The substr(buffer, total) call previously allocated a new
    * string per message — replaced with offset arithmetic that only
    * compacts the head when the consumed prefix is large.
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

      while ($size - $offset >= 5) {
         $type = $buffer[$offset];
         // @ Inline 32-bit unsigned big-endian read — avoids unpack() syscall.
         $length = (ord($buffer[$offset + 1]) << 24)
                 | (ord($buffer[$offset + 2]) << 16)
                 | (ord($buffer[$offset + 3]) << 8)
                 | ord($buffer[$offset + 4]);

         if ($length < 4) {
            throw new InvalidArgumentException('PostgreSQL message length is invalid.');
         }

         $total = $length + 1;

         if ($size - $offset < $total) {
            break;
         }

         $base = $offset + 5;
         $offset += $total;

         // @@ Hot backend types decode straight from the shared buffer —
         //    no payload substr, no parse()/read() frames, integers inlined.
         //    The driver only consumes Message->fields, so hot messages skip
         //    retaining the payload string entirely.
         $Messages[] = match ($type) {
            'D' => new Message($type, '', [
               'values' => $this->fetch($buffer, $base, $base + $length - 4),
            ]),
            'Z' => new Message($type, '', [
               'status' => $buffer[$base] ?? '',
            ]),
            'C' => new Message($type, '', [
               'command' => substr($buffer, $base, $length - 5),
            ]),
            'T' => new Message($type, '', [
               'columns' => $this->describe($buffer, $base, $base + $length - 4),
            ]),
            '1' => new Message($type, '', [
               'status' => 'parse',
            ]),
            '2' => new Message($type, '', [
               'status' => 'bind',
            ]),
            default => $this->parse($type, substr($buffer, $base, $length - 4)),
         };
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
    * Parse a complete (cold-path) backend message payload.
    *
    * Hot types (D, Z, C, T, 1, 2) never reach here — decode() reads them
    * straight from the shared buffer.
    */
   private function parse (string $type, string $payload): Message
   {
      return match ($type) {
         'R' => new Message($type, $payload, $this->read($payload, 'auth')),
         'E' => new Message($type, $payload, $this->read($payload, 'errors')),
         'N' => new Message($type, $payload, [
            'notice' => $this->read($payload, 'errors'),
         ]),
         'S' => new Message($type, $payload, $this->read($payload, 'status')),
         'K' => new Message($type, $payload, [
            'process' => $this->unpack('N', $payload, 0),
            'secret' => $this->unpack('N', $payload, 4),
         ]),
         'A' => new Message($type, $payload, $this->read($payload, 'notification')),
         't' => new Message($type, $payload, [
            'parameters' => $this->read($payload, 'oids'),
         ]),
         'n' => new Message($type, $payload, [
            'status' => 'none',
         ]),
         's' => new Message($type, $payload, [
            'status' => 'suspended',
         ]),
         default => new Message($type, $payload),
      };
   }

   /**
    * Read one DataRow (D) field list straight from the stream buffer.
    *
    * Integers are inlined ord() shifts — one method frame per row instead of
    * one unpack() frame per cell; cell strings are the only allocations.
    *
    * @return array<int,null|string>
    */
   private function fetch (string $buffer, int $base, int $end): array
   {
      // ?
      if ($base + 2 > $end) {
         return [];
      }

      $Fields = [];
      $count = (ord($buffer[$base]) << 8) | ord($buffer[$base + 1]);
      $cursor = $base + 2;

      // @@
      for ($index = 0; $index < $count; $index++) {
         if ($cursor + 4 > $end) {
            break;
         }

         $length = (ord($buffer[$cursor]) << 24)
                 | (ord($buffer[$cursor + 1]) << 16)
                 | (ord($buffer[$cursor + 2]) << 8)
                 | ord($buffer[$cursor + 3]);
         $cursor += 4;

         // ? PostgreSQL encodes NULL field values as unsigned -1 (0xFFFFFFFF)
         if ($length === 0xFFFFFFFF) {
            $Fields[] = null;

            continue;
         }

         if ($cursor + $length > $end) {
            break;
         }

         $Fields[] = substr($buffer, $cursor, $length);
         $cursor += $length;
      }

      // :
      return $Fields;
   }

   /**
    * Read one RowDescription (T) column list straight from the stream buffer.
    *
    * @return array<int,array<string,int|string>>
    */
   private function describe (string $buffer, int $base, int $end): array
   {
      // ?
      if ($base + 2 > $end) {
         return [];
      }

      $Fields = [];
      $count = (ord($buffer[$base]) << 8) | ord($buffer[$base + 1]);
      $cursor = $base + 2;

      // @@
      for ($index = 0; $index < $count; $index++) {
         $stop = strpos($buffer, "\0", $cursor);

         if ($stop === false || $stop + 19 > $end) {
            break;
         }

         $name = substr($buffer, $cursor, $stop - $cursor);
         $cursor = $stop + 1;

         $Fields[] = [
            'name' => $name,
            'table' => (ord($buffer[$cursor]) << 24) | (ord($buffer[$cursor + 1]) << 16)
                     | (ord($buffer[$cursor + 2]) << 8) | ord($buffer[$cursor + 3]),
            'attribute' => (ord($buffer[$cursor + 4]) << 8) | ord($buffer[$cursor + 5]),
            'type' => (ord($buffer[$cursor + 6]) << 24) | (ord($buffer[$cursor + 7]) << 16)
                    | (ord($buffer[$cursor + 8]) << 8) | ord($buffer[$cursor + 9]),
            'size' => (ord($buffer[$cursor + 10]) << 8) | ord($buffer[$cursor + 11]),
            'modifier' => (ord($buffer[$cursor + 12]) << 24) | (ord($buffer[$cursor + 13]) << 16)
                        | (ord($buffer[$cursor + 14]) << 8) | ord($buffer[$cursor + 15]),
            'format' => (ord($buffer[$cursor + 16]) << 8) | ord($buffer[$cursor + 17]),
         ];
         $cursor += 18;
      }

      // :
      return $Fields;
   }

   /**
    * Read structured fields from a backend payload.
    *
    * @return array<int|string,mixed>
    */
   private function read (string $payload, string $mode): array
   {
      $Fields = [];
      $offset = 0;
      $size = strlen($payload);

      if ($mode === 'auth') {
         $code = $this->unpack('N', $payload, 0);
         $Fields = [
            'code' => $code,
         ];

         if ($code === 5 && $size >= 8) {
            $Fields['salt'] = substr($payload, 4, 4);
         }

         if ($code === 10) {
            $Fields['mechanisms'] = $this->read(substr($payload, 4), 'strings');
         }

         if ($code === 11 || $code === 12) {
            $Fields['data'] = substr($payload, 4);
         }

         return $Fields;
      }

      if ($mode === 'errors') {
         while ($offset < $size && $payload[$offset] !== "\0") {
            $code = $payload[$offset];
            $offset++;
            $end = strpos($payload, "\0", $offset);

            if ($end === false) {
               break;
            }

            $name = self::ERRORS[$code] ?? $code;
            $Fields[$name] = substr($payload, $offset, $end - $offset);
            $offset = $end + 1;
         }

         return $Fields;
      }

      if ($mode === 'strings') {
         while ($offset < $size && $payload[$offset] !== "\0") {
            $end = strpos($payload, "\0", $offset);

            if ($end === false) {
               break;
            }

            $Fields[] = substr($payload, $offset, $end - $offset);
            $offset = $end + 1;
         }

         return $Fields;
      }

      if ($mode === 'status') {
         $Strings = $this->read($payload, 'strings');

         return [
            'name' => $Strings[0] ?? '',
            'value' => $Strings[1] ?? '',
         ];
      }

      if ($mode === 'notification') {
         if ($size < 4) {
            return $Fields;
         }

         $Strings = $this->read(substr($payload, 4), 'strings');

         return [
            'process' => $this->unpack('N', $payload, 0),
            'channel' => $Strings[0] ?? '',
            'payload' => $Strings[1] ?? '',
         ];
      }

      if ($mode === 'oids') {
         if ($size < 2) {
            return $Fields;
         }

         $count = $this->unpack('n', $payload, 0);
         $offset = 2;

         for ($index = 0; $index < $count; $index++) {
            if ($offset + 4 > $size) {
               break;
            }

            $Fields[] = $this->unpack('N', $payload, $offset);
            $offset += 4;
         }

         return $Fields;
      }

      return $Fields;
   }

   /**
    * Unpack an unsigned integer from a payload offset.
    *
    * Uses raw byte indexing + bit shifting instead of PHP's unpack(), which
    * has to parse the format string and allocate the result array on every
    * call. This path is invoked for every column, row field and OID, so the
    * function-call cost would otherwise dominate decoder time.
    */
   private function unpack (string $format, string $payload, int $offset): int
   {
      $width = $format === 'n' ? 2 : 4;

      if ($offset + $width > strlen($payload)) {
         throw new InvalidArgumentException('PostgreSQL payload ended before integer boundary.');
      }

      if ($format === 'n') {
         return (ord($payload[$offset]) << 8) | ord($payload[$offset + 1]);
      }

      return (ord($payload[$offset]) << 24)
           | (ord($payload[$offset + 1]) << 16)
           | (ord($payload[$offset + 2]) << 8)
           | ord($payload[$offset + 3]);
   }
}
