<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Web\TCP\Client;


use Bootgly\CLI\_\ {
   Logger\Logging
};

use Bootgly\Web; // @interface

use Bootgly\Web\TCP\Client;
use Bootgly\Web\TCP\Client\Connections;
use Bootgly\Web\TCP\Client\Connections\Connection;


class Packages implements Web\Packages
{
   use Logging;


   public Connection $Connection;

   // * Data
   // @ Buffer
   public static string $output;
   public static string $input;
   // * Meta
   public int $written;
   public int $read;
   // @ Stats
   public int $writes;
   public int $reads;
   public array $errors;


   public function __construct (Connection &$Connection)
   {
      $this->Connection = $Connection;

      // * Data
      // @ Buffer
      self::$output ='';
      self::$input = '';
      // * Meta
      $this->written = 0;         // Output Data length (bytes written).
      $this->read = 0;            // Input Data length (bytes read).
      // @ Stats
      $this->writes = 0;          // Socket Write count
      $this->reads = 0;           // Socket Read count
      $this->errors['write'] = 0; // Socket Writing errors
      $this->errors['read'] = 0;  // Socket Reading errors
   }

   public function fail ($Socket, string $operation, $result)
   {
      try {
         $eof = @feof($Socket);
      } catch (\Throwable) {
         $eof = false;
      }

      // @ Check connection reset?
      if ($eof) {
         $this->log(
            'Failed to ' . $operation . ' package: End-of-file!' . PHP_EOL,
            self::LOG_WARNING_LEVEL
         );

         $this->Connection->close();

         return true;
      }

      // @ Check connection close intention?
      if ($result === 0) {
         #$this->log('Failed to ' . $operation . ' package: 0 byte handled!' . PHP_EOL);
      }

      if (is_resource($Socket) && get_resource_type($Socket) === 'stream') {
         $this->log(
            'Failed to ' . $operation . ' package: closing connection...' . PHP_EOL,
            self::LOG_WARNING_LEVEL
         );

         $this->Connection->close();
      }

      Connections::$errors[$operation]++;

      return false;
   }

   public function write (&$Socket, ? int $length = null)
   {
      try {
         // @ Prepare to send data
         $buffer = self::$output;
         $sent = false;
         $written = 0;

         // @ Send initial part of data
         if ($length !== null) {
            $initial = substr($buffer, 0, $length);
            $sent = @fwrite($Socket, $initial, $length);
         }

         // @ Send entire or remaining of data if exists
         while ($buffer) {
            $sent = @fwrite($Socket, $buffer, $length);

            if ($sent === false) {
               break;
            }

            if ($sent > 0 && $sent < $length) {
               $buffer = substr($buffer, $sent);
               $length -= $sent;
               $written += $sent;
            } else if ($sent === 0) {
               continue;
            } else {
               $written += $sent;
               break;
            }
         }
      } catch (\Throwable) {
         $written = false;
      }

      // @ Check issues
      if ($written === 0 || $written === false) {
         try {
            $eof = @feof($Socket);
         } catch (\Throwable) {
            $eof = false;
         }

         // @ Check connection close/reset by server?
         if ($eof) {
            $this->fail($Socket, 'write', $written);
            return false;
         }

         // @ Check connection close intention by peer?
         if ($written === 0) {
            #$this->log('Failed to write data: 0 bytes written!' . PHP_EOL);
         }

         $this->errors['write']++;

         return false;
      }

      // @ Set Stats
      if (Connections::$stats) {
         // Global
         Connections::$writes++;
         Connections::$written += $written;
         // Per client
         if ( isSet(Connections::$Connections[(int) $Socket]) ) {
            Connections::$Connections[(int) $Socket]->writes++;
         }
      }

      if (Client::$onWrite) {
         (Client::$onWrite)($Socket, $this, $this->Connection);
      }

      return true;
   }

   public function read (&$Socket)
   {
      try {
         $input = fread($Socket, 65535);
      } catch (\Throwable) {}

      // @ Check connection close intention by server?
      // Close connection if input data is empty to avoid unnecessary loop?
      if ($input === '') {
         #$this->log('Failed to read buffer: input data is empty!' . PHP_EOL, self::LOG_WARNING_LEVEL);
         #$this->fail($Socket, 'read', $input);
         return false;
      }

      // @ Check issues
      if ($input === false) {
         $this->fail($Socket, 'read', $input);
      }

      // @ Set Stats (disable to max performance in benchmarks)
      $length = strlen($input);

      if (Connections::$stats) {
         // Global
         Connections::$reads++;
         Connections::$read += $length;
         // Per client
         #Connections::$Connections[(int) $Socket]['reads']++;
      }

      if (Client::$onRead) {
         (Client::$onRead)($Socket, $this, $this->Connection);
      }

      return true;
   }
}
