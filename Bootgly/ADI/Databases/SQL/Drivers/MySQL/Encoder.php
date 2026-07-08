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


use function chr;
use function count;
use function intdiv;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_scalar;
use function is_string;
use function ord;
use function pack;
use function str_repeat;
use function strlen;
use function substr;
use DateTimeInterface;
use InvalidArgumentException;

use Bootgly\ADI\Database\Config;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL\Capabilities;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL\Decoder;


/**
 * MySQL client packet encoder.
 *
 * Builds complete wire packets — 3-byte little-endian payload length +
 * sequence id + payload — splitting payloads at the 16 MB boundary.
 */
class Encoder
{
   public const string SSL = 'ssl';
   public const string RESPONSE = 'response';
   public const string AUTH = 'auth';
   public const string QUERY = 'query';
   public const string PREPARE = 'prepare';
   public const string EXECUTE = 'execute';
   public const string CLOSE = 'close';

   // # Command bytes (protocol::Command)
   public const string COM_QUERY = "\x03";
   public const string COM_STMT_PREPARE = "\x16";
   public const string COM_STMT_EXECUTE = "\x17";
   public const string COM_STMT_CLOSE = "\x19";

   /** Client-side maximum packet size announced during the handshake. */
   private const int PACKET_MAX = 16777216;

   // * Config
   // ...

   // * Data
   // ...

   // * Metadata
   // ...


   /**
    * Encode a MySQL client packet.
    */
   public function encode (string $message, mixed $payload = null, int $sequence = 0): string
   {
      return match ($message) {
         self::SSL => $this->secure($payload, $sequence),
         self::RESPONSE => $this->respond($payload, $sequence),
         self::AUTH => $this->reply($payload, $sequence),
         self::QUERY => $this->query($payload),
         self::PREPARE => $this->prepare($payload),
         self::EXECUTE => $this->execute($payload),
         self::CLOSE => $this->close($payload),
         default => throw new InvalidArgumentException("MySQL message is not supported: {$message}."),
      };
   }

   /**
    * Frame one payload into wire packets, splitting at the 16 MB boundary.
    */
   public function frame (string $payload, int $sequence = 0): string
   {
      $size = strlen($payload);

      // ?: Common case — single packet
      if ($size < Decoder::PAYLOAD_MAX) {
         return pack('V', $size | ($sequence << 24)) . $payload;
      }

      $packets = '';
      $offset = 0;

      // @@ Full 16 MB chunks + the closing shorter packet (possibly empty)
      while (true) {
         $chunk = substr($payload, $offset, Decoder::PAYLOAD_MAX);
         $length = strlen($chunk);
         $packets .= pack('V', $length | ($sequence << 24)) . $chunk;
         $sequence = ($sequence + 1) & 0xFF;
         $offset += $length;

         if ($length < Decoder::PAYLOAD_MAX) {
            break;
         }
      }

      // :
      return $packets;
   }

   /**
    * Encode an SSLRequest packet (32-byte handshake prefix).
    */
   private function secure (mixed $payload, int $sequence): string
   {
      if (is_array($payload) === false || is_int($payload['capabilities'] ?? null) === false) {
         throw new InvalidArgumentException('MySQL SSLRequest payload must contain the capabilities integer.');
      }

      $body = pack('V', $payload['capabilities'])
         . pack('V', self::PACKET_MAX)
         . chr(Capabilities::CHARSET_UTF8MB4)
         . str_repeat("\0", 23);

      // :
      return $this->frame($body, $sequence);
   }

   /**
    * Encode a HandshakeResponse41 packet.
    */
   private function respond (mixed $payload, int $sequence): string
   {
      if (
         is_array($payload) === false
         || is_int($payload['capabilities'] ?? null) === false
         || is_string($payload['auth'] ?? null) === false
         || is_string($payload['plugin'] ?? null) === false
         || ($payload['config'] ?? null) instanceof Config === false
      ) {
         throw new InvalidArgumentException('MySQL handshake response payload must contain capabilities, auth, plugin and config.');
      }

      $capabilities = $payload['capabilities'];
      $Config = $payload['config'];

      $body = pack('V', $capabilities)
         . pack('V', self::PACKET_MAX)
         . chr(Capabilities::CHARSET_UTF8MB4)
         . str_repeat("\0", 23)
         . "{$Config->username}\0"
         . $this->pack(strlen($payload['auth'])) . $payload['auth'];

      if ($capabilities & Capabilities::CONNECT_WITH_DB) {
         $body .= "{$Config->database}\0";
      }

      if ($capabilities & Capabilities::PLUGIN_AUTH) {
         $body .= "{$payload['plugin']}\0";
      }

      // :
      return $this->frame($body, $sequence);
   }

   /**
    * Encode a raw authentication continuation packet.
    */
   private function reply (mixed $payload, int $sequence): string
   {
      if (is_string($payload) === false) {
         throw new InvalidArgumentException('MySQL authentication payload must be a string.');
      }

      // :
      return $this->frame($payload, $sequence);
   }

   /**
    * Encode a COM_QUERY command packet.
    */
   private function query (mixed $payload): string
   {
      if (is_string($payload) === false) {
         throw new InvalidArgumentException('MySQL query payload must be a SQL string.');
      }

      // : Command packets always restart the sequence at 0
      return $this->frame(self::COM_QUERY . $payload, 0);
   }

   /**
    * Encode a COM_STMT_PREPARE command packet.
    */
   private function prepare (mixed $payload): string
   {
      if (is_string($payload) === false) {
         throw new InvalidArgumentException('MySQL prepare payload must be a SQL string.');
      }

      // :
      return $this->frame(self::COM_STMT_PREPARE . $payload, 0);
   }

   /**
    * Encode a COM_STMT_EXECUTE command packet (binary protocol).
    */
   private function execute (mixed $payload): string
   {
      if (
         is_array($payload) === false
         || is_int($payload['statement'] ?? null) === false
         || is_array($payload['parameters'] ?? null) === false
      ) {
         throw new InvalidArgumentException('MySQL execute payload must contain the statement id and parameters.');
      }

      // ! Header — statement id, CURSOR_TYPE_NO_CURSOR, iteration count 1
      $body = self::COM_STMT_EXECUTE
         . pack('V', $payload['statement'])
         . "\x00"
         . pack('V', 1);

      $parameters = $payload['parameters'];
      $count = count($parameters);

      if ($count > 0) {
         $bitmap = str_repeat("\0", intdiv($count + 7, 8));
         $types = '';
         $values = '';
         $index = 0;

         // @@
         foreach ($parameters as $parameter) {
            if ($parameter === null) {
               $bitmap[intdiv($index, 8)] = chr(ord($bitmap[intdiv($index, 8)]) | (1 << ($index % 8)));
               $types .= chr(Decoder::TYPE_NULL) . "\0";
            }
            elseif (is_bool($parameter)) {
               $types .= chr(Decoder::TYPE_TINY) . "\0";
               $values .= chr($parameter ? 1 : 0);
            }
            elseif (is_int($parameter)) {
               $types .= chr(Decoder::TYPE_LONGLONG) . "\0";
               $values .= pack('P', $parameter);
            }
            elseif (is_float($parameter)) {
               $types .= chr(Decoder::TYPE_DOUBLE) . "\0";
               $values .= pack('e', $parameter);
            }
            elseif ($parameter instanceof DateTimeInterface) {
               $formatted = $parameter->format('Y-m-d H:i:s.u');
               $types .= chr(Decoder::TYPE_VAR_STRING) . "\0";
               $values .= $this->pack(strlen($formatted)) . $formatted;
            }
            elseif (is_scalar($parameter)) {
               $string = (string) $parameter;
               $types .= chr(Decoder::TYPE_VAR_STRING) . "\0";
               $values .= $this->pack(strlen($string)) . $string;
            }
            else {
               throw new InvalidArgumentException("MySQL cannot bind the parameter at position {$index}.");
            }

            $index++;
         }

         // ! NULL bitmap + new-params-bound flag + type pairs + values
         $body .= $bitmap . "\x01" . $types . $values;
      }

      // :
      return $this->frame($body, 0);
   }

   /**
    * Encode a COM_STMT_CLOSE command packet (no server response).
    */
   private function close (mixed $payload): string
   {
      if (is_int($payload) === false) {
         throw new InvalidArgumentException('MySQL close payload must be the statement id.');
      }

      // :
      return $this->frame(self::COM_STMT_CLOSE . pack('V', $payload), 0);
   }

   /**
    * Pack one length-encoded integer.
    */
   private function pack (int $value): string
   {
      // ?:
      if ($value < 0xFB) {
         return chr($value);
      }

      if ($value <= 0xFFFF) {
         return "\xFC" . pack('v', $value);
      }

      if ($value <= 0xFFFFFF) {
         return "\xFD" . substr(pack('V', $value), 0, 3);
      }

      // :
      return "\xFE" . pack('P', $value);
   }
}
