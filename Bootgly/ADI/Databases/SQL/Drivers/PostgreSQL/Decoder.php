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

         $payload = substr($buffer, $offset + 5, $length - 4);
         $offset += $total;

         $Messages[] = $this->parse($type, $payload);
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
    * Parse a complete backend message payload.
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
         'Z' => new Message($type, $payload, [
            'status' => $payload[0] ?? '',
         ]),
         'C' => new Message($type, $payload, [
            'command' => substr($payload, 0, -1),
         ]),
         'T' => new Message($type, $payload, [
            'columns' => $this->read($payload, 'columns'),
         ]),
         'D' => new Message($type, $payload, [
            'values' => $this->read($payload, 'values'),
         ]),
         '1' => new Message($type, $payload, [
            'status' => 'parse',
         ]),
         '2' => new Message($type, $payload, [
            'status' => 'bind',
         ]),
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

      if ($mode === 'columns') {
         if ($size < 2) {
            return $Fields;
         }

         $count = $this->unpack('n', $payload, 0);
         $offset = 2;

         for ($index = 0; $index < $count; $index++) {
            $end = strpos($payload, "\0", $offset);

            if ($end === false || $end + 19 > $size) {
               break;
            }

            $name = substr($payload, $offset, $end - $offset);
            $offset = $end + 1;

            $Fields[] = [
               'name' => $name,
               'table' => $this->unpack('N', $payload, $offset),
               'attribute' => $this->unpack('n', $payload, $offset + 4),
               'type' => $this->unpack('N', $payload, $offset + 6),
               'size' => $this->unpack('n', $payload, $offset + 10),
               'modifier' => $this->unpack('N', $payload, $offset + 12),
               'format' => $this->unpack('n', $payload, $offset + 16),
            ];
            $offset += 18;
         }

         return $Fields;
      }

      if ($mode === 'values') {
         if ($size < 2) {
            return $Fields;
         }

         $count = $this->unpack('n', $payload, 0);
         $offset = 2;

         for ($index = 0; $index < $count; $index++) {
            if ($offset + 4 > $size) {
               break;
            }

            $length = $this->unpack('N', $payload, $offset);
            $offset += 4;

            // PostgreSQL encodes NULL field values as unsigned -1 (0xFFFFFFFF).
            if ($length === 0xFFFFFFFF) {
               $Fields[] = null;

               continue;
            }

            if ($offset + $length > $size) {
               break;
            }

            $Fields[] = substr($payload, $offset, $length);
            $offset += $length;
         }
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
