<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\TCP\Server;


use Bootgly\ACI\Logs\Logger;
use Bootgly\ACI\Logs\LoggableEscaped;

use Bootgly\API\Server as SAPI;

use Bootgly\WPI; // @interface

use Bootgly\WPI\Interfaces\TCP\Server;
use Bootgly\WPI\Interfaces\TCP\Server\Connections;
use Bootgly\WPI\Interfaces\TCP\Server\Connections\Connection;


abstract class Packages implements WPI\Packages
{
   use LoggableEscaped;


   public Connection $Connection;

   // * Config
   public bool $cache;

   // * Data
   public bool $changed;
   // @ IO
   public static string $input;
   public static string $output;

   // * Metadata
   // @ Handler
   public array $callbacks;
   // @ Stream
   public array $downloading;
   public array $uploading;
   // @ Expiration
   public bool $expired;


   public function __construct (Connection &$Connection)
   {
      $this->Logger = new Logger(channel: __CLASS__);
      $this->Connection = $Connection;


      // * Config
      $this->cache = true;

      // * Data
      $this->changed = true;
      // @ IO
      self::$input = '';
      self::$output = '';

      // * Metadata
      // @ Handler
      $this->callbacks = [&self::$input];
      // @ Stream
      $this->downloading = [];
      $this->uploading = [];
      // @ Expiration
      $this->expired = false;
   }

   public function fail ($Socket, string $operation)
   {
      try {
         $EOF = @\feof($Socket);
      }
      catch (\Throwable) {
         $EOF = false;
      }

      // @ Check connection reset (End-Of-File)?
      if ($EOF) {
         #$this->log('Failed to ' . $operation . ' package: End-Of-File!' . PHP_EOL);
         Connections::$errors[$operation]++;
         $this->Connection->close();
         return true;
      }

      if (\is_resource($Socket) === false || \get_resource_type($Socket) !== 'stream') {
         #$this->log('Failed to ' . $operation . ' package: closing connection...' . PHP_EOL);
         Connections::$errors[$operation]++;
         $this->Connection->close();
         return true;
      }

      return false;
   }

   public function reading (&$Socket, ? int $length = null, ? int $timeout = null) : bool
   {
      try {
         $input = '';
         $received = 0; // @ Bytes received from client
         $total = $length ?? 0; // @ Total length of packet = the expected length of packet or 0

         if ($length > 0 || $timeout > 0) {
            $started = \microtime(true);
         }

         do {
            $buffer = @\fread($Socket, $length ?? 65535);

            if ($buffer === false) break;
            if ($buffer === '') {
               if (! $timeout > 0 || \microtime(true) - $started >= $timeout) {
                  $this->expired = true;
                  break;
               }

               continue; // TODO check EOF?
            }

            $input .= $buffer;

            $bytes = \strlen($buffer);
            $received += $bytes;

            if ($length) {
               $length -= $bytes;
               continue;
            }

            break;
         }
         while ($received < $total || $total === 0);
      }
      catch (\Throwable) {
         $buffer = false;
      }

      // @ Check connection close intention by peer?
      // Close connection if input data is empty to avoid unnecessary loop
      if ($buffer === '') {
         #$this->log('Failed to read buffer: input data is empty!' . PHP_EOL, self::LOG_WARNING_LEVEL);
         $this->Connection->close();
         return false;
      }

      // @ Check issues
      if ($buffer === false) {
         return $this->fail($Socket, 'read');
      }

      // @ On success
      $this->changed = (self::$input !== $input);

      // @ Handle cache and set Input
      if ($this->cache === false || $this->changed === true) {
         self::$input = $input;
      }

      // @ Set Stats (disable to max performance in benchmarks)
      if (Connections::$stats) {
         // Global
         Connections::$reads++;
         Connections::$read += $received;
         // Per client
         #Connections::$Connections[(int) $Socket]->reads++;
      }

      // @ Write data
      if (Server::$Decoder) { // @ Decode Application Data if exists
         $received = Server::$Decoder::decode($this, $input, $received);
      }

      if ($received) {
         $this->writing($Socket);
      }

      return true;
   }
   // ---
   public function writing (&$Socket, ? int $length = null) : bool
   {
      if (Server::$Encoder) { // @ Encode Application Data if exists
         $buffer = Server::$Encoder::encode($this, $length);
      }
      else {
         $buffer = (SAPI::$Handler)(...$this->callbacks);
      }

      try {
         $written = null;

         while ($buffer) {
            $sent = @\fwrite($Socket, $buffer, $length);

            if ($sent === false) break;
            if ($sent === 0) {
               continue; // TODO check EOF?
            }

            $written += $sent;

            if ($sent < $length) {
               $buffer = \substr($buffer, $sent);
               $length -= $sent;
               continue;
            }

            if ( \count($this->uploading) ) {
               $written += $this->uploading($Socket);
            }

            break;
         };
      }
      catch (\Throwable) {
         $sent = false;
      }

      // @ Close Connection
      if ($sent === false) {
         $this->Connection->close();
      }
      // @ Fail
      if ($written === 0) {
         return $this->fail($Socket, 'write');
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

      return true;
   }
   public function read (&$Socket)
   {
      // N/A
   }
   public function write (&$Socket, ? int $length = null)
   {
      // N/A
   }

   // ! Stream
   public function downloading ($Socket) : int|false
   {
      // TODO test!!!
      $file = $this->downloading[0]['file'];
      $Handler = @\fopen($file, 'w+');

      $length = $this->downloading[0]['length'];
      $close = $this->downloading[0]['close'];

      $read = 0; // int Socket read in bytes

      // @ Check free space in dir of file
      try {
         if (\disk_free_space(\dirname($file)) < $length) {
            return false;
         }
      }
      catch (\Throwable) {
         return false;
      }

      // @ Set over / rate
      $over = 0;
      $rate = 1 * 1024 * 1024; // 1 MB (1048576) = Max rate to read/send data file by loop

      if ($length > 0 && $length < $rate) {
         $rate = $length;
      }
      else if ($length > $rate) {
         $over = $length % $rate;
      }

      // @ Download File
      if ($over > 0) {
         $read += $this->download($Socket, $Handler, $over, $over);
         $length -= $over;
      }

      $read += $this->download($Socket, $Handler, $rate, $length);
   
      // @ Try to close the file Handler
      try {
         @\fclose($Handler);
      }
      catch (\Throwable) {}

      // @ Unset current downloading
      unSet($this->downloading[0]);

      // @ Try to close the Socket if requested
      if ($close) {
         try {
            $this->Connection->close();
         }
         catch (\Throwable) {}
      }
   
      return $read;
   }
   public function uploading ($Socket) : int
   { // TODO support to upload multiple files
      $Handler = @\fopen($this->uploading[0]['file'], 'r');
      $parts = $this->uploading[0]['parts'];
      $pads = $this->uploading[0]['pads'];
      $close = $this->uploading[0]['close'];

      $written = 0;

      foreach ($parts as $index => $part) {
         $offset = $part['offset'];
         $length = $part['length'];

         $pad = $pads[$index] ?? null;

         // @ Move pointer of file to offset
         try {
            @\fseek($Handler, $offset, SEEK_SET);
         }
         catch (\Throwable) {
            return $written;
         }

         // @ Prepend
         if ( ! empty($pad['prepend']) ) {
            try {
               $sent = @\fwrite($Socket, $pad['prepend']);
            } catch (\Throwable) {
               break;
            }

            if ($sent === false) break;

            $written += $sent;
            // TODO check if the data has been completely sent
         }

         // @ Set over / rate
         $over = 0;
         $rate = 1 * 1024 * 1024; // 1 MB (1048576) = Max rate to read/send data file by loop

         if ($length < $rate) {
            $rate = $length;
         }
         else if ($length > $rate) {
            $over = $length % $rate;
         }

         // @ Upload File
         if ($over > 0) {
            $written += $this->upload($Socket, $Handler, $over, $over);
            // TODO check if the data has been completely sent
            $length -= $over;
         }

         $written += $this->upload($Socket, $Handler, $rate, $length);

         // @ Append
         if ( ! empty($pad['append']) ) {
            try {
               $sent = @\fwrite($Socket, $pad['append']);
            }
            catch (\Throwable) {
               break;
            }

            if ($sent === false) break;

            $written += $sent;
            // TODO check if the data has been completely sent
         }
      }

      // @ Try to close the file Handler
      try {
         @\fclose($Handler);
      }
      catch (\Throwable) {}

      // @ Unset current uploading
      unSet($this->uploading[0]);

      // @ Try to close the Socket if requested
      if ($close) {
         try {
            $this->Connection->close();
         }
         catch (\Throwable) {}
      }

      return $written;
   }
   // ---
   public function download (&$Socket, &$Handler, int $rate, int $length) : int
   {
      // TODO test!!!
      $read = 0;
      $stored = 0;

      while ($stored < $length) {
         // ! Socket
         // @ Read buffer from Client
         try {
            $buffer = @\fread($Socket, $rate);
         }
         catch (\Throwable) {
            break;
         }

         if ($buffer === false) break;

         $read += \strlen($buffer);

         // @ Write part of data (if exists) using Handler
         while ($read) {
            // ! File
            try {
               $written = @\fwrite($Handler, $buffer, $read);
            }
            catch (\Throwable) {
               break;
            }

            if ($written === false) break;
            if ($written === 0) continue;

            $stored += $written;

            if ($written < $read) {
               $buffer = \substr($buffer, $written);
               $read -= $written;
               continue;
            }

            break;
         }

         // @ Check Socket EOF (End-Of-File)
         try {
            $end = @\feof($Socket);
         }
         catch (\Throwable) {
            break;
         }

         if ($end) break;
      }

      return $stored;
   }
   public function upload (&$Socket, &$Handler, int $rate, int $length) : int
   {
      $written = 0;

      while ($written < $length) {
         // ! Stream
         // @ Read buffer using Handler
         try {
            $buffer = @\fread($Handler, $rate);
         }
         catch (\Throwable) {
            break;
         }

         if ($buffer === false) break;

         $read = \strlen($buffer);

         // @ Write part of data (if exists) to Client
         while ($read) {
            // ! Socket
            try {
               $sent = @\fwrite($Socket, $buffer, $read);
            }
            catch (\Throwable) {
               break;
            }

            if ($sent === false) break;
            if ($sent === 0) continue; // TODO check EOF?

            $written += $sent;

            if ($sent < $read) {
               $buffer = \substr($buffer, $sent);
               $read -= $sent;
               continue;
            }

            break;
         }

         // @ Check Handler EOF (End-Of-File)
         try {
            $end = @\feof($Handler);
         }
         catch (\Throwable) {
            break;
         }

         if ($end) break;
      }

      return $written;
   }

   public function reject (string $raw)
   {
      try {
         @\fwrite($this->Connection->Socket, $raw);
      }
      catch (\Throwable) {
         // ...
      }

      $this->Connection->close();
   }
}
