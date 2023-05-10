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


use Bootgly\CLI\Terminal\_\ {
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

   // * Config
   // ...

   // * Data
   // @ IO
   public static string $output;
   public static string $input;

   // * Meta
   public int $written;
   public int $read;
   // @ Stats
   public int $writes;
   public int $reads;
   public array $errors;
   // @ Expiration
   public bool $expired;


   public function __construct (Connection &$Connection)
   {
      $this->Connection = $Connection;

      // * Config
      // ...

      // * Data
      // @ IO
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
      // @ Expiration
      $this->expired = false;
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
         $buffer = self::$output;
         $written = 0;
         #$length ??= strlen($buffer);

         while ($buffer) {
            $sent = @fwrite($Socket, $buffer, $length);
            #$sent = @stream_socket_sendto($Socket, $buffer, $length???);

            if ($sent === false) break;
            if ($sent === 0) continue; // TODO check EOF?

            $written += $sent;

            if ($sent < $length) {
               $buffer = substr($buffer, $sent);
               $length -= $sent;
               continue;
            }

            break;
         }
      } catch (\Throwable) {
         $sent = false;
      }

      // @ Check issues
      if (! $written || ! $sent) {
         return $this->fail($Socket, 'write', $written);
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
         (Client::$onWrite)($Socket, $this->Connection, $this);
      }

      return true;
   }

   public function read (&$Socket, ? int $length = null, ? int $timeout = null)
   {
      try {
         $input = '';
         $received = 0; // @ Bytes received from server
         $total = $length ?? 0; // @ Total length of packet = the expected length of packet or 0

         if ($length > 0 || $timeout > 0) {
            $started = microtime(true);
         }

         do {
            $buffer = @fread($Socket, $length ?? 65535);
            #$buffer = @stream_socket_recvfrom($Socket, $length ?? 65535);

            if ($buffer === false) break;
            if ($buffer === '') {
               if (! $timeout > 0 || microtime(true) - $started >= $timeout) {
                  $this->expired = true;
                  break;
               }

               continue; // TODO check EOF?
            }

            $input .= $buffer;

            $bytes = strlen($buffer);
            $received += $bytes;

            if ($length) {
               $length -= $bytes;
               continue;
            }

            break;
         } while ($received < $total || $total === 0);
      } catch (\Throwable) {
         $buffer = false;
      }

      // @ Check connection close intention by server?
      // Close connection if input data is empty to avoid unnecessary loop?
      // TODO remove?
      if ($buffer === '') {
         return false;
      }

      // @ Check issues
      if ($buffer === false) {
         return $this->fail($Socket, 'read', $buffer);
      }

      // @ Set Input
      self::$input = $input;

      // @ Set Stats (disable to max performance in benchmarks)
      if (Connections::$stats) {
         // Global
         Connections::$reads++;
         Connections::$read += $received;
         // Per client
         #Connections::$Connections[(int) $Socket]['reads']++;
      }

      if (Client::$onRead) {
         (Client::$onRead)($Socket, $this->Connection, $this);
      }

      return true;
   }
}
