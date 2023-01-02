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
   // @ Buffer
   public static string $input = '';
   public string $output;
   public bool $changed;
   // * Meta
   public int $read;
   public int|false $written;
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
      // @ Buffer
      #self::$input = '';
      $this->output = '';
      $this->changed = false;
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
      } catch (\Throwable) {}

      // Close connection if input data is empty
      if ($input === '') {
         $this->Connection->close($Socket);
         return false;
      }

      // @ Check issues
      if ($input === false) {
         try {
            if (@feof($Socket)) {
               #$this->log('Failed to read buffer: End-of-file!' . PHP_EOL);
               $this->Connection->close($Socket);
               return false;
            }
         } catch (\Throwable) {}

         if (is_resource($Socket) === false || get_resource_type($Socket) !== 'stream') {
            $this->errors['read']++;
            #$this->log('Failed to read buffer!' . PHP_EOL);
            $this->Connection->close($Socket);
            return false;
         }
      }

      // @ Set Buffer input
      self::$input !== $input ? $this->changed = true : $this->changed = false;
      if ($this->cache === false || $this->changed === true) {
         #self::$input .= $input;
         self::$input = $input;
      }

      // @ On success
      // Global stats
      $this->reads++;
      $this->read += strlen($input);
      // Per client stats
      @$this->Connection->peers[(int) $Socket]['stats']['reads']++;

      // TODO test it
      if ($write) {
         #Server::$Event->add($Socket, Server::$Event::EVENT_WRITE, 'write');
         $this->write($Socket);
      }

      return true;
   }
   public function write (&$Socket, bool $handle = true, ? int $length = null) : bool
   {
      // @ Set Buffer output
      if ($handle) {
         $this->output = ($this->Connection->Server->handler)(...$this->callbacks);
      }

      $written = 0;
      try {
         while (true) {
            $written = @fwrite($Socket, $this->output, $length);

            if ($written === false) {
               break;
            }

            // Check if the entire message has been sented
            if ($written < $length) { // If not sent the entire message 
               // Get the part of the message that has not yet been sented as message
               $this->output = substr($this->output, $written);
               // Get the length of the not sented part
               $length -= $written;
            } else {
               break;
            }
         }

         #$written = @fwrite($Socket, $this->output, $length);

         #@fflush($Socket);
      } catch (\Throwable) {}

      // @ Check issues
      if ($written === 0 || $written === false) {
         try {
            if (@feof($Socket)) {
               #$this->log('Failed to write data: End-of-file!' . PHP_EOL);
               $this->Connection->close($Socket);
               return false;
            }
         } catch (\Throwable) {}

         if (is_resource($Socket) === false || get_resource_type($Socket) !== 'stream') {
            #$this->log('Failed to write data: resource is not a stream!' . PHP_EOL);
            $this->Connection->close($Socket);
            return false;
         }

         if ($written === 0) {
            $this->errors['write']++;
            #$this->log('Failed to write data: 0 bytes written!' . PHP_EOL);
            $this->Connection->close($Socket);
            return false;
         }

         if ($written === false) {
            $this->errors['write']++;
            #$this->log('Failed to write data: unknown error!' . PHP_EOL);
            $this->Connection->close($Socket);
            return false;
         }
      }

      // @ On success
      if ($handle) {
         #Server::$Event->del($Socket, Server::$Event::EVENT_WRITE);
         // Reset Buffer
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