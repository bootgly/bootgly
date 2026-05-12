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


use function strlen;
use function strpos;
use function substr;
use function unpack;
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
   // ...


   /**
    * Decode backend messages from an incremental byte stream.
    *
    * @return array<int,Message>
    */
   public function decode (string $bytes): array
   {
      $this->buffer .= $bytes;
      $Messages = [];

      while (strlen($this->buffer) >= 5) {
         $type = $this->buffer[0];
         $length = $this->unpack('N', $this->buffer, 1);

         if ($length < 4) {
            throw new InvalidArgumentException('PostgreSQL message length is invalid.');
         }

         $total = $length + 1;

         if (strlen($this->buffer) < $total) {
            break;
         }

         $payload = substr($this->buffer, 5, $length - 4);
         $this->buffer = substr($this->buffer, $total);

         $Messages[] = $this->parse($type, $payload);
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
    */
   private function unpack (string $format, string $payload, int $offset): int
   {
      $width = $format === 'n' ? 2 : 4;
      $bytes = substr($payload, $offset, $width);

      if (strlen($bytes) !== $width) {
         throw new InvalidArgumentException('PostgreSQL payload ended before integer boundary.');
      }

      $data = unpack("{$format}value", $bytes);

      if ($data === false) {
         throw new InvalidArgumentException('PostgreSQL integer unpack failed.');
      }

      return (int) $data['value'];
   }
}
