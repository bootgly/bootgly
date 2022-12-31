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

   // * Data
   public static string|array|false $input = '';
   public string $output;
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

      // * Data
      self::$input = '';
      $this->output = '';
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
      #self::$input = '';

      try {
         $input = @fread($Socket, 65535);
      } catch (\Throwable $Throwable) {}

      // Close connection if input data is empty
      if ($input === '') {
         $this->Connection->close($Socket);
         return false;
      }

      // @ Check issues
      if ($input === false) {
         if (feof($Socket)) {
            $this->errors['read']++;
            #$this->log('Failed to read buffer: End-of-file!' . PHP_EOL);
            $this->Connection->close($Socket);
            return false;
         }

         if (is_resource($Socket) === false || get_resource_type($Socket) !== 'stream') {
            $this->errors['read']++;
            #$this->log('Failed to read buffer!' . PHP_EOL);
            $this->Connection->close($Socket);
            return false;
         }
      }

      // @ On success
      self::$input .= $input;

      // @ Write stats
      // Global
      $this->reads++;
      $this->read += strlen(self::$input);
      // Per client
      $this->Connection->peers[(int) $Socket]['stats']['reads']++;

      // TODO test it
      if ($write) {
         #Server::$Event->add($Socket, Server::$Event::EVENT_WRITE, [$this, 'write']);
         $this->write($Socket);
      }

      return true;
   }
   public function write (&$Socket, bool $handle = true) : bool
   {
      if ($handle) {
         $this->output = ($this->Connection->Server->handler)(...$this->callbacks);
      }

      $written = 0;
      try {
         $written = @fwrite($Socket, $this->output);
      } catch (\Throwable $Throwable) {}

      // @ Check issues
      if ($written === 0 || $written === false) {
         if (feof($Socket)) {
            $this->errors['write']++;
            #$this->log('Failed to write data: End-of-file!' . PHP_EOL);
            $this->Connection->close($Socket);
            return false;
         }

         if (is_resource($Socket) === false || get_resource_type($Socket) !== 'stream') {
            $this->errors['write']++;
            $this->log('Failed to write data!' . PHP_EOL);
            $this->Connection->close($Socket);
            return false;
         }
      }

      // @ On success
      #Server::$Event->del($Socket, Server::$Event::EVENT_WRITE);

      if ($handle) {
         self::$input = '';
      }

      // @ Write Stats
      // Global
      $this->writes++;
      $this->written += strlen($this->output);
      // Per client
      $this->Connection->peers[(int) $Socket]['stats']['writes']++;

      return true;
   }
}

return new Data($this->Connection);