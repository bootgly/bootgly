<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Web\TCP\Server\Connections;


use Bootgly\CLI\_\ {
   Logger\Logging
};

use Bootgly\Web\TCP\Server;
use Bootgly\Web\TCP\Server\Connection;
use Bootgly\Web\TCP\Server\Connections;


class Data implements Connections
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
   public int $read;
   public int $written;
   // @ Stats
   public int $reads;
   public int $writes;
   public array $errors;
   // @ Handler
   public array $callbacks;


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
      $this->read = 0;            // Input Data length (bytes read).
      $this->written = 0;         // Output Data length (bytes written).
      // @ Stats
      $this->reads = 0;           // Socket Read count
      $this->writes = 0;          // Socket Write count
      $this->errors['read'] = 0;  // Socket Reading errors
      $this->errors['write'] = 0; // Socket Writing errors
      // @ Handler
      $this->callbacks = [&self::$input];
   }

   public function read (&$Socket, bool $write = true) : bool
   {
      try {
         $input = @fread($Socket, 65535);
      } catch (\Throwable) {
         $input = false;
      }

      // $this->log($input);

      // @ Check connection close intention by peer?
      // Close connection if input data is empty to avoid unnecessary loop
      if ($input === '') {
         // $this->log('Failed to read buffer: input data is empty!' . PHP_EOL);
         // Server::$Event->del($Socket, Server::$Event::EVENT_WRITE);
         $this->Connection->close($Socket);
         return false;
      }

      // @ Check issues
      if ($input === false) {
         try {
            $eof = @feof($Socket);
         } catch (\Throwable) {
            $eof = false;
         }

         if ($eof) {
            // $this->log('Failed to read buffer: End-of-file!' . PHP_EOL);
            $this->Connection->close($Socket);
            return false;
         }

         if (is_resource($Socket) && get_resource_type($Socket) === 'stream') {
            $this->log('Failed to read buffer: closing connection...' . PHP_EOL);
            $this->Connection->close($Socket);
         }

         $this->errors['read']++;

         return false;
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

      // @ Write Stats
      // Global
      $this->reads++;
      $this->read += strlen($input);
      // Per client
      @$this->Connection->peers[(int) $Socket]['stats']['reads']++;

      // @ Write Data
      if ($write) {
         // TODO implement this data write by default?
         #Server::$Event->add($Socket, Server::$Event::EVENT_WRITE, 'write');

         $this->write($Socket);
      }

      return true;
   }
   public function write (&$Socket, bool $handle = true, ? int $length = null) : bool
   {
      // @ Set Output
      if ($handle)
         self::$output = ($this->Connection->handler)(...$this->callbacks);

      try {
         $buffer = self::$output;

         while (true) {
            $written = @fwrite($Socket, $buffer, $length);

            if ($written === false) {
               break;
            }

            if ($written < $length) {
               $buffer = substr($buffer, $written);
               $length -= $written;
            } else {
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

         // @ Check connection reset by peer?
         if ($eof) {
            $this->log('Failed to write data: End-of-file!' . PHP_EOL);
            $this->Connection->close($Socket);
            return false;
         }

         // @ Check connection close intention by server?
         if ($written === 0) {
            $this->log('Failed to write data: 0 bytes written!' . PHP_EOL);
         }

         if (is_resource($Socket) && get_resource_type($Socket) === 'stream') {
            // $this->log('Failed to write data: closing connection...' . PHP_EOL);
            $this->Connection->close($Socket);
            return false;
         }

         $this->errors['write']++;

         return false;
      }

      // @ On success
      if ($handle) {
         #Server::$Event->del($Socket, Server::$Event::EVENT_WRITE);

         // Reset Input Buffer
         self::$input = '';
      }

      // @ Write Stats
      // Global
      $this->writes++;
      $this->written += $written;
      // Per client
      @$this->Connection->peers[(int) $Socket]['stats']['writes']++;

      return true;
   }
}

return new Data($this->Connection);