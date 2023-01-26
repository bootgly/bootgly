<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Web\TCP\Server;


use Bootgly\SAPI;

use Bootgly\CLI\_\ {
   Logger\Logging // @trait
};

use Bootgly\Web; // @interface

use Bootgly\Web\TCP\Server;
use Bootgly\Web\TCP\Server\Connections;
use Bootgly\Web\TCP\Server\Connections\Connection;


abstract class Packages implements Web\Packages
{
   use Logging;


   public Connection $Connection;

   // * Config
   public bool $cache;
   // * Data
   public bool $changed;
   // @ Buffer
   public static string $input;
   public static string $output;
   // * Meta
   // @ Handler
   public array $handlers;
   public array $callbacks; // TODO move


   public function __construct (Connection &$Connection)
   {
      $this->Connection = $Connection;

      // * Config
      $this->cache = true;
      // * Data
      $this->changed = true;
      // @ Buffer
      self::$input = '';
      self::$output = '';
      // * Meta
      // @ Handler
      $this->handlers = [];
      $this->callbacks = [&self::$input];

      SAPI::boot(true);
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
         #$this->log('Failed to ' . $operation . ' package: End-of-file!' . PHP_EOL);
         $this->Connection->close();
         return true;
      }

      // @ Check connection close intention?
      if ($result === 0) {
         $this->log('Failed to ' . $operation . ' package: 0 byte handled!' . PHP_EOL);
      }

      if (is_resource($Socket) && get_resource_type($Socket) === 'stream') {
         $this->log('Failed to ' . $operation . ' package: closing connection...' . PHP_EOL);
         $this->Connection->close();
      }

      Connections::$errors[$operation]++;

      return false;
   }

   public function read (&$Socket) : bool
   {
      try {
         $input = @fread($Socket, 65535);
      } catch (\Throwable) {
         $input = false;
      }

      $this->log($input);

      // @ Check connection close intention by peer?
      // Close connection if input data is empty to avoid unnecessary loop
      if ($input === '') {
         #$this->log('Failed to read buffer: input data is empty!' . PHP_EOL, self::LOG_WARNING_LEVEL);
         $this->Connection->close();
         return false;
      }

      // @ Check issues
      if ($input === false) {
         return $this->fail($Socket, 'read', $input);
      }

      // @ On success
      if (self::$input !== $input) {
         $this->changed = true;
      } else {
         $this->changed = false;
      }

      // @ Set Input
      if ($this->cache === false || $this->changed === true) {
         #self::$input .= $input;
         self::$input = $input;
      }

      // @ Set Stats (disable to max performance in benchmarks)
      if (Connections::$stats) {
         // Global
         Connections::$reads++;
         Connections::$read += strlen($input);
         // Per client
         #Connections::$Connections[(int) $Socket]['reads']++;
      }

      // @ Write data
      if (Server::$Application) { // @ Decode Application Data if exists
         $input = Server::$Application::decode($this);
      }

      if ($input) {
         $this->write($Socket);
      }

      return true;
   }
   public function write (&$Socket, ? int $length = null) : bool
   {
      // @ Set output buffer
      if (Server::$Application) {
         self::$output = Server::$Application::encode($this, $length);
      } else {
         self::$output = (SAPI::$Handler)(...$this->callbacks);
      }

      try {
         // @ Prepare to send data
         $buffer = self::$output;
         $sent = false;
         $written = 0;

         // @ Send initial part of data
         if ($length) {
            $initial = substr($buffer, 0, $length);
            $sent = @fwrite($Socket, $initial, $length);
         }

         // @ Stream with file handlers if exists
         if ( ! empty($this->handlers) ) {
            throw new \Exception; // TODO use another catch Object
         }

         // @ Set remaining of data if exists
         if ($sent) {
            $buffer = substr($buffer, $sent);
            $length = strlen($buffer);
         }

         // @ Send entire or remaining of data if exists
         while (true) {
            $sent = @fwrite($Socket, $buffer, $length);

            if ($sent === false) {
               break;
            }

            if ($sent > 0 && $sent < $length) { // @ Stream data
               $buffer = substr($buffer, $sent);
               $length -= $sent;
               $written += $sent;
            } else if ($sent === 0) {
               continue; // TODO check EOF?
            } else {
               $written += $sent;
               break;
            }
         }
      } catch (\Exception) { // TODO use another catch Object
         $written = $this->stream($Socket, 0);
      } catch (\Error) {
         $written = false;
      }

      // @ Check issues
      if ($written === 0 || $written === false || $sent === false) {
         return $this->fail($Socket, 'write', $written);
      }

      // @ Set Stats (disable to max performance in benchmarks)
      if (Connections::$stats) {
         // Global
         Connections::$writes++;
         Connections::$written += $written;
         // Per client
         Connections::$Connections[(int) $Socket]->writes++;
      }

      return true;
   }
   public function stream ($Socket, int $offset = 0, ? int $length = null)
   {
      // TODO support to offset
      // TODO support to send multiple files

      $length = $this->handlers[0]['size'];
      $handler = $this->handlers[0]['handler'];

      $sent = 0;

      while ($sent < $length) {
         $rate = 1 * 1024; // @ Set upload speed rate by loop cycle

         // @ Read file from disk
         try {
            $buffer = @fread($handler, $rate);
         } catch (\Throwable) {
            $buffer = false;
         }

         if ($buffer === '' || $buffer === false) {
            try {
               @fclose($handler);
            } catch (\Throwable) {}

            break;
         }

         // @ Send part of read file to client
         $read = strlen($buffer);

         while (true) {
            try {
               $written = @fwrite($Socket, $buffer);
            } catch (\Throwable) {
               $written = false;
            }

            if ($written === false) {
               break;
            }

            if ($written === 0) {
               continue;
            } else if ($written < $read) {
               $buffer = substr($buffer, $written);
               $read = strlen($buffer);
            } else {
               $sent += $written;
               break;
            }
         }

         // @ Check End-of-file
         try {
            $end = @feof($handler);
         } catch (\Throwable) {
            $end = true;
         }

         if ($end) {
            break;
         }
      }

      return $sent;
   }

   public function reject (string $raw)
   {
      try {
         @fwrite($this->Connection->Socket, $raw);
      } catch (\Throwable) {}

      $this->Connection->close();
   }
}
