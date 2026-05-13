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


use function count;
use function is_array;
use function is_bool;
use function is_scalar;
use function is_string;
use function pack;
use function strlen;
use InvalidArgumentException;

use Bootgly\ADI\Database\Config;


/**
 * PostgreSQL frontend message encoder.
 */
class Encoder
{
   public const string STARTUP = 'startup';
   public const string PASSWORD = 'password';
   public const string BIND = 'bind';
   public const string CANCEL = 'cancel';
   public const string CLOSE = 'close';
   public const string DESCRIBE = 'describe';
   public const string EXECUTE = 'execute';
   public const string PARSE = 'parse';
   public const string QUERY = 'query';
   public const string RESPONSE = 'response';
   public const string SASL = 'sasl';
   public const string SSL = 'ssl';
   public const string SYNC = 'sync';

   /** Precomputed Sync packet — its 5-byte wire form is constant. */
   public const string SYNC_BYTES = "S\x00\x00\x00\x04";

   // * Config
   // ...

   // * Data
   // ...

   // * Metadata
   // ...


   /**
    * Encode a PostgreSQL frontend message.
    */
   public function encode (string $message, mixed $payload = null): string
   {
      return match ($message) {
         self::STARTUP => $this->start($payload),
         self::PASSWORD => $this->authenticate($payload),
         self::BIND => $this->bind($payload),
         self::CANCEL => $this->cancel($payload),
         self::CLOSE => $this->close($payload),
         self::DESCRIBE => $this->describe($payload),
         self::EXECUTE => $this->execute($payload),
         self::PARSE => $this->parse($payload),
         self::QUERY => $this->query($payload),
         self::RESPONSE => $this->reply($payload),
         self::SASL => $this->negotiate($payload),
         self::SSL => $this->secure(),
         self::SYNC => $this->sync(),
         default => throw new InvalidArgumentException("PostgreSQL message is not supported: {$message}."),
      };
   }

   /**
    * Encode a StartupMessage.
    */
   private function start (mixed $payload): string
   {
      if ($payload instanceof Config === false) {
         throw new InvalidArgumentException('PostgreSQL startup payload must be a Config.');
      }

      $body = "user\0{$payload->username}\0database\0{$payload->database}\0client_encoding\0UTF8\0application_name\0Bootgly\0\0";
      $length = pack('N', strlen($body) + 8);
      $version = pack('N', 196608);

      return "{$length}{$version}{$body}";
   }

   /**
    * Encode a CancelRequest packet.
    */
   private function cancel (mixed $payload): string
   {
      if (is_array($payload) === false || is_int($payload['process'] ?? null) === false || is_int($payload['secret'] ?? null) === false) {
         throw new InvalidArgumentException('PostgreSQL CancelRequest payload must contain process and secret integers.');
      }

      $body = pack('N', 80877102) . pack('N', $payload['process']) . pack('N', $payload['secret']);
      $length = pack('N', strlen($body) + 4);

      return "{$length}{$body}";
   }

   /**
    * Encode a Close statement or portal message.
    */
   private function close (mixed $payload): string
   {
      if (is_array($payload) === false || is_string($payload['type'] ?? null) === false || is_string($payload['name'] ?? null) === false) {
         throw new InvalidArgumentException('PostgreSQL Close payload must contain type and name strings.');
      }

      $type = $payload['type'];

      if ($type !== 'S' && $type !== 'P') {
         throw new InvalidArgumentException('PostgreSQL Close type must be statement or portal.');
      }

      $name = $payload['name'];
      $body = "{$type}{$name}\0";
      $length = pack('N', strlen($body) + 4);

      return "C{$length}{$body}";
   }

   /**
    * Encode a PasswordMessage.
    */
   private function authenticate (mixed $payload): string
   {
      if (is_string($payload) === false) {
         throw new InvalidArgumentException('PostgreSQL password payload must be a string.');
      }

      $length = pack('N', strlen($payload) + 5);

      return "p{$length}{$payload}\0";
   }

   /**
    * Encode a SASLInitialResponse message.
    */
   private function negotiate (mixed $payload): string
   {
      if (is_array($payload) === false || is_string($payload['mechanism'] ?? null) === false || is_string($payload['response'] ?? null) === false) {
         throw new InvalidArgumentException('PostgreSQL SASL payload must contain mechanism and response strings.');
      }

      $mechanism = $payload['mechanism'];
      $response = $payload['response'];
      $responseLength = pack('N', strlen($response));
      $length = pack('N', strlen($mechanism) + strlen($response) + 9);

      return "p{$length}{$mechanism}\0{$responseLength}{$response}";
   }

   /**
    * Encode a SASLResponse message.
    */
   private function reply (mixed $payload): string
   {
      if (is_string($payload) === false) {
         throw new InvalidArgumentException('PostgreSQL SASL response payload must be a string.');
      }

      $length = pack('N', strlen($payload) + 4);

      return "p{$length}{$payload}";
   }

   /**
    * Encode a Simple Query message.
    */
   public function query (mixed $payload): string
   {
      if (is_string($payload) === false) {
         throw new InvalidArgumentException('PostgreSQL query payload must be a string.');
      }

      $length = pack('N', strlen($payload) + 5);

      return "Q{$length}{$payload}\0";
   }

   /**
    * Encode a Parse message.
    */
   public function parse (mixed $payload): string
   {
      if (is_array($payload) === false || is_string($payload['statement'] ?? null) === false || is_string($payload['sql'] ?? null) === false) {
         throw new InvalidArgumentException('PostgreSQL Parse payload must contain statement and sql strings.');
      }

      $statement = $payload['statement'];
      $sql = $payload['sql'];
      $types = is_array($payload['types'] ?? null) ? $payload['types'] : [];
      $typeCount = pack('n', count($types));
      $typeBytes = '';

      foreach ($types as $type) {
         $type = is_scalar($type) ? (int) $type : 0;
         $typeBytes .= pack('N', $type);
      }

      $body = "{$statement}\0{$sql}\0{$typeCount}{$typeBytes}";
      $length = pack('N', strlen($body) + 4);

      return "P{$length}{$body}";
   }

   /**
    * Encode a Bind message with per-parameter format selection.
    */
   public function bind (mixed $payload): string
   {
      if (is_array($payload) === false || is_string($payload['portal'] ?? null) === false || is_string($payload['statement'] ?? null) === false) {
         throw new InvalidArgumentException('PostgreSQL Bind payload must contain portal and statement strings.');
      }

      $portal = $payload['portal'];
      $statement = $payload['statement'];
      $parameters = is_array($payload['parameters'] ?? null) ? $payload['parameters'] : [];
      $types = is_array($payload['types'] ?? null) ? $payload['types'] : [];
      $formats = [];
      $formatBytes = '';
      $formatted = [];
      $position = 0;

      foreach ($parameters as $parameter) {
         $type = is_scalar($types[$position] ?? null) ? (int) $types[$position] : 0;
         $format = $this->select($type);
         $formats[] = $format;
         $formatted[] = $this->format($parameter, $type, $format);
         $position++;
      }

      foreach ($formats as $format) {
         $formatBytes .= pack('n', $format);
      }

      $formatCount = $formatBytes === '' || $this->check($formats)
         ? pack('n', 0)
         : pack('n', count($formats)) . $formatBytes;
      $parameterCount = pack('n', count($parameters));
      $parameterBytes = '';

      foreach ($formatted as $parameter) {
         if ($parameter === null) {
            $parameterBytes .= pack('N', 0xFFFFFFFF);

            continue;
         }

         $parameterLength = pack('N', strlen($parameter));
         $parameterBytes .= "{$parameterLength}{$parameter}";
      }

      $resultFormatCount = pack('n', 0);
      $body = "{$portal}\0{$statement}\0{$formatCount}{$parameterCount}{$parameterBytes}{$resultFormatCount}";
      $length = pack('N', strlen($body) + 4);

      return "B{$length}{$body}";
   }

   /**
    * Encode a Describe statement or portal message.
    */
   public function describe (mixed $payload): string
   {
      $type = 'P';
      $name = '';

      if (is_string($payload)) {
         $name = $payload;
      }
      else if (is_array($payload) && is_string($payload['type'] ?? null) && is_string($payload['name'] ?? null)) {
         $type = $payload['type'];
         $name = $payload['name'];
      }
      else {
         throw new InvalidArgumentException('PostgreSQL Describe payload must be a portal string or type/name array.');
      }

      if ($type !== 'S' && $type !== 'P') {
         throw new InvalidArgumentException('PostgreSQL Describe type must be statement or portal.');
      }

      $body = "{$type}{$name}\0";
      $length = pack('N', strlen($body) + 4);

      return "D{$length}{$body}";
   }

   /**
    * Encode an Execute message.
    */
   public function execute (mixed $payload): string
   {
      if (is_string($payload) === false) {
         throw new InvalidArgumentException('PostgreSQL Execute payload must be a portal string.');
      }

      $rows = pack('N', 0);
      $body = "{$payload}\0{$rows}";
      $length = pack('N', strlen($body) + 4);

      return "E{$length}{$body}";
   }

   /**
    * Encode an SSLRequest message.
    */
   private function secure (): string
   {
      $length = pack('N', 8);
      $code = pack('N', 80877103);

      return "{$length}{$code}";
   }

   /**
    * Encode a Sync message.
    */
   public function sync (): string
   {
      return self::SYNC_BYTES;
   }

   /**
    * Format a PHP value as PostgreSQL parameter bytes.
    */
   private function format (mixed $value, int $type = 0, int $format = 0): null|string
   {
      if ($value === null) {
         return null;
      }

      if ($format === 1) {
         return $this->pack($value, $type);
      }

      if (is_bool($value)) {
         return $value ? 't' : 'f';
      }

      if (is_scalar($value)) {
         return (string) $value;
      }

      throw new InvalidArgumentException('PostgreSQL parameter must be scalar or null.');
   }

   /**
    * Select one PostgreSQL parameter format code by OID.
    */
   private function select (int $type): int
   {
      return match ($type) {
         16, 21, 23, 700, 701 => 1,
         default => 0,
      };
   }

   /**
    * Check whether all parameter formats are text.
    *
    * @param array<int,int> $formats
    */
   private function check (array $formats): bool
   {
      foreach ($formats as $format) {
         if ($format !== 0) {
            return false;
         }
      }

      return true;
   }

   /**
    * Pack one PHP value as a PostgreSQL binary parameter.
    */
   private function pack (mixed $value, int $type): string
   {
      if (is_scalar($value) === false) {
         throw new InvalidArgumentException('PostgreSQL binary parameter must be scalar.');
      }

      if ($type !== 16 && $type !== 21 && $type !== 23 && $type !== 700 && $type !== 701) {
         throw new InvalidArgumentException('PostgreSQL binary parameter type is unsupported.');
      }

      return match ($type) {
         16 => $value ? "\x01" : "\x00",
         21 => pack('n', ((int) $value) & 0xFFFF),
         23 => pack('N', ((int) $value) & 0xFFFFFFFF),
         700 => pack('G', (float) $value),
         701 => pack('E', (float) $value),
      };
   }
}
