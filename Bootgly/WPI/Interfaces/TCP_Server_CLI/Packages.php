<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\TCP_Server_CLI;


use const SEEK_SET;
use function array_key_exists;
use function disk_free_space;
use function dirname;
use function fclose;
use function feof;
use function fopen;
use function fread;
use function fseek;
use function fwrite;
use function get_resource_type;
use function is_array;
use function is_int;
use function is_resource;
use function is_string;
use function microtime;
use function strlen;
use function substr;
use Throwable;

use Bootgly\ACI\Logs\Logger;
use Bootgly\ACI\Logs\LoggableEscaped;
use Bootgly\API\Server as SAPI;
use Bootgly\WPI; // @interface
use Bootgly\WPI\Interfaces\TCP_Server_CLI as Server;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;


// FIXME: extends Packages
abstract class Packages implements WPI\Connections\Packages
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
   /** @var array<string> */
   public array $callbacks;
   // @ Stream
   /** @var array<int, array<string, mixed>> */
   public array $downloading;
   /** @var array<int, array<string, mixed>> */
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

   /**
    * Fail to read/write data
    *
    * @param resource $Socket 
    * @param string $operation 
    *
    * @return bool 
    */
   public function fail ($Socket, string $operation): bool
   {
      try {
         $EOF = @feof($Socket);
      }
      catch (Throwable) {
         $EOF = false;
      }

      // @ Check connection reset (End-Of-File)?
      if ($EOF) {
         #$this->log('Failed to ' . $operation . ' package: End-Of-File!' . PHP_EOL);
         Connections::$errors[$operation]++;
         $this->Connection->close();
         return true;
      }

      if (is_resource($Socket) === false || get_resource_type($Socket) !== 'stream') {
         #$this->log('Failed to ' . $operation . ' package: closing connection...' . PHP_EOL);
         Connections::$errors[$operation]++;
         $this->Connection->close();
         return true;
      }

      return false;
   }

   /**
    * Read data from the client
    *
    * @param resource $Socket 
    * @param null|int<1, max> $length
    * @param null|int<0, max> $timeout
    *
    * @return bool 
    */
   public function reading (
      &$Socket, null|int $length = null, null|int $timeout = null
   ): bool
   {
      // !
      $input = '';
      $received = 0; // Bytes received from client
      $total = $length ?? 0; // Total length of packet = the expected length of packet or 0
      // * Metadata
      $started = 0;
      if ($length > 0 || $timeout > 0) {
         $started = microtime(true);
      }

      // @
      try {
         do {
            $buffer = @fread($Socket, $length ?? 65535); // @phpstan-ignore-line

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
      }
      catch (Throwable) {
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
   /**
    * Write data to the client
    *
    * @param resource $Socket 
    * @param int<0,max>|null $length 
    *
    * @return bool 
    */
   public function writing (&$Socket, null|int $length = null): bool
   {
      // !
      if (Server::$Encoder) { // @ Encode Application Data if exists
         $buffer = Server::$Encoder::encode($this, $length);
      }
      else {
         /** @var string $buffer */
         $buffer = (SAPI::$Handler)(...$this->callbacks);
      }

      // !
      $written = 0;
      $failed = false;
      $sent = 0; // Bytes sent to client per write loop iteration

      // @
      try {
         while ($buffer) {
            $sent = @fwrite($Socket, $buffer, $length); // @phpstan-ignore-line

            if ($sent === false) {
               $failed = true;
               break;
            }
            if ($sent === 0) {
               continue; // TODO check EOF?
            }

            $written += $sent;

            if ($sent < $length) {
               $buffer = substr($buffer, $sent);
               $length -= $sent;
               continue;
            }

            if ( count($this->uploading) ) {
               $written += $this->uploading($Socket);
            }

            break;
         };
      }
      catch (Throwable) {
         $sent = false;
      }

      // @ Close Connection
      if ($sent === false) {
         $this->Connection->close();
      }
      // @ Fail
      if ($failed) {
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
   public function read (&$Socket): void
   {
      // N/A
   }
   public function write (&$Socket, null|int $length = null): void
   {
      // N/A
   }

   // ! Stream
   /**
    * Download file from the client
    *
    * @param resource $Socket 
    *
    * @return int|false
    * @throws Throwable
    */
   public function downloading ($Socket): int|false
   {
      // TODO test!!!
      $queued = $this->downloading[0] ?? null;
      if (! is_array($queued)) {
         return false;
      }

      if (! array_key_exists('file', $queued) || ! is_string($queued['file']) || $queued['file'] === '') {
         return false;
      }
      $file = $queued['file'];

      $Handler = @fopen($file, 'w+');
      if ($Handler === false) {
         return false;
      }

      if (! array_key_exists('length', $queued) || ! is_int($queued['length'])) {
         @fclose($Handler);
         return false;
      }
      $length = $queued['length'];

      $close = array_key_exists('close', $queued) ? (bool) $queued['close'] : false;

      $read = 0; // int Socket read in bytes

      // @ Check free space in dir of file
      try {
         if (disk_free_space(dirname($file)) < $length) {
            return false;
         }
      }
      catch (Throwable) {
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
         @fclose($Handler);
      }
      catch (Throwable) {}

      // @ Unset current downloading
      unSet($this->downloading[0]);

      // @ Try to close the Socket if requested
      if ($close) {
         try {
            $this->Connection->close();
         }
         catch (Throwable) {}
      }
   
      return $read;
   }
   /**
    * Upload file to the client
    *
    * @param resource $Socket 
    *
    * @return int
    * @throws Throwable
    */
   public function uploading ($Socket): int
   { // TODO support to upload multiple files
      $queued = $this->uploading[0] ?? null;
      if (! is_array($queued)) {
         return 0;
      }

      if (! array_key_exists('file', $queued) || ! is_string($queued['file']) || $queued['file'] === '') {
         return 0;
      }
      $file = $queued['file'];

      $Handler = @fopen($file, 'r');
      if ($Handler === false) {
         return 0;
      }
      $parts = (array) ($queued['parts'] ?? []);
      $pads = (array) ($queued['pads'] ?? []);
      $close = array_key_exists('close', $queued) ? (bool) $queued['close'] : false;

      $written = 0;

      foreach ($parts as $index => $part) {
         if (! is_array($part)) {
            continue;
         }

         $offsetValue = $part['offset'] ?? null;
         $lengthValue = $part['length'] ?? null;
         if (! is_int($offsetValue) || ! is_int($lengthValue)) {
            continue;
         }

         $offset = $offsetValue;
         $length = $lengthValue;

         $pad = $pads[$index] ?? null;
         $prepend = '';
         $append = '';

         if (is_array($pad)) {
            $prependValue = $pad['prepend'] ?? null;
            $appendValue = $pad['append'] ?? null;
            $prepend = is_string($prependValue) ? $prependValue : '';
            $append = is_string($appendValue) ? $appendValue : '';
         }

         // @ Move pointer of file to offset
         try {
            @fseek($Handler, $offset, SEEK_SET);
         }
         catch (Throwable) {
            return $written;
         }

         // @ Prepend
         if ($prepend !== '') {
            try {
               $sent = @fwrite($Socket, $prepend);
            }
            catch (Throwable) {
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

         $written += $this->upload($Socket, $Handler, $rate, $length); // @phpstan-ignore-line

         // @ Append
         if ($append !== '') {
            try {
               $sent = @fwrite($Socket, $append);
            }
            catch (Throwable) {
               break;
            }

            if ($sent === false) break;

            $written += $sent;
            // TODO check if the data has been completely sent
         }
      }

      // @ Try to close the file Handler
      try {
         @fclose($Handler);
      }
      catch (Throwable) {}

      // @ Unset current uploading
      unSet($this->uploading[0]);

      // @ Try to close the Socket if requested
      if ($close) {
         try {
            $this->Connection->close();
         }
         catch (Throwable) {}
      }

      return $written;
   }
   // ---
   /**
    * Download data from the client
    *
    * @param resource $Socket 
    * @param resource $Handler 
    * @param int $rate 
    * @param int $length 
    *
    * @return int 
    */
   public function download (&$Socket, &$Handler, int $rate, int $length): int
   {
      // TODO test!!!
      $read = 0;
      $stored = 0;

      while ($stored < $length) {
         // ! Socket
         // @ Read buffer from Client
         try {
            $buffer = @fread($Socket, $rate); // @phpstan-ignore-line
         }
         catch (Throwable) {
            break;
         }

         if ($buffer === false) break;

         $read += strlen($buffer);

         // @ Write part of data (if exists) using Handler
         while ($read) {
            // ! File
            try {
               $written = @fwrite($Handler, $buffer, $read); // @phpstan-ignore-line
            }
            catch (Throwable) {
               break;
            }

            if ($written === false) break;
            if ($written === 0) continue;

            $stored += $written;

            if ($written < $read) {
               $buffer = substr($buffer, $written);
               $read -= $written;
               continue;
            }

            break;
         }

         // @ Check Socket EOF (End-Of-File)
         try {
            $end = @feof($Socket);
         }
         catch (Throwable) {
            break;
         }

         if ($end) break;
      }

      return $stored;
   }
   /**
    * Upload data to the client.
    *
    * @param resource $Socket 
    * @param resource $Handler 
    * @param int<1,max> $rate 
    * @param int $length 
    *
    * @return int 
    */
   public function upload (&$Socket, &$Handler, int $rate, int $length): int
   {
      $written = 0;

      while ($written < $length) {
         // ! Stream
         // @ Read buffer using Handler
         try {
            $buffer = @fread($Handler, $rate);
         }
         catch (Throwable) {
            break;
         }

         if ($buffer === false) break;

         $read = strlen($buffer);

         // @ Write part of data (if exists) to Client
         while ($read) {
            // ! Socket
            try {
               $sent = @fwrite($Socket, $buffer, $read); // @phpstan-ignore-line
            }
            catch (Throwable) {
               break;
            }

            if ($sent === false) break;
            if ($sent === 0) continue; // TODO check EOF?

            $written += $sent;

            if ($sent < $read) {
               $buffer = substr($buffer, $sent);
               $read -= $sent;
               continue;
            }

            break;
         }

         // @ Check Handler EOF (End-Of-File)
         try {
            $end = @feof($Handler);
         }
         catch (Throwable) {
            break;
         }

         if ($end) break;
      }

      return $written;
   }

   public function reject (string $raw): void
   {
      try {
         @fwrite($this->Connection->Socket, $raw);
      }
      catch (Throwable) {
         // ...
      }

      $this->Connection->close();
   }
}
