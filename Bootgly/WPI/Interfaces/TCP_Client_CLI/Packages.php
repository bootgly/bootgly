<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\TCP_Client_CLI;


use Bootgly\ACI\Logs\LoggableEscaped;

use Bootgly\WPI; // @interface

use Bootgly\WPI\Interfaces\TCP_Client_CLI as Client;
use Bootgly\WPI\Interfaces\TCP_Client_CLI\Connections;
use Bootgly\WPI\Interfaces\TCP_Client_CLI\Connections\Connection;


// FIXME: extends Packages
class Packages implements WPI\Connections\Packages
{
   use LoggableEscaped;


   public Connection $Connection;

   // * Config
   // ...

   // * Data
   // @ IO
   public static string $output;
   public static string $input;

   // * Metadata
   public int $written;
   public int $read;
   // @ Stats
   public int $writes;
   public int $reads;
   /** @var array<string,int> */
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

      // * Metadata
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

   /**
    * Handle failed package operation.
    * 
    * @param resource $Socket
    * @param string $operation
    * @param mixed $result
    *
    * @return bool
    */
   public function fail ($Socket, string $operation, mixed $result): bool
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
   /**
    * Write data to server.
    * 
    * @param resource $Socket
    * @param int|null $length
    *
    * @return bool
    */
   public function writing (&$Socket, ? int $length = null): bool
   {
      // !
      $buffer = self::$output;
      $written = 0;
      #$length ??= strlen($buffer);
      $sent = 0; // Bytes sent to server per write loop iteration

      // @
      try {
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
      }
      catch (\Throwable) {
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
   /**
    * Read data from server.
    * 
    * @param resource $Socket
    * @param int|null $length
    * @param int|null $timeout
    *
    * @return bool
    */
   public function reading (
      &$Socket, ? int $length = null, ? int $timeout = null
   ): bool
   {
      // !
      $input = '';
      $received = 0; // Bytes received from server
      $total = $length ?? 0; // Total length of packet = the expected length of packet or 0

      $started = 0;
      if ($length > 0 || $timeout > 0) {
         $started = microtime(true);
      }

      // @
      try {
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
         }
         while ($received < $total || $total === 0);
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

   public function write (&$Socket): void
   {}
   public function read (&$Socket): void
   {}
}
