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
         #$this->log('Failed to write package: End-of-file!' . PHP_EOL);
         $this->Connection->close($Socket);
         return false;
      }

      // @ Check connection close intention?
      if ($result === 0) {
         $this->log('Failed to ' . $operation . ' package: 0 byte handled!' . PHP_EOL);
      }

      if (is_resource($Socket) && get_resource_type($Socket) === 'stream') {
         $this->log('Failed to ' . $operation . ' package: closing connection...' . PHP_EOL);
         $this->Connection->close($Socket);
      }

      Connections::$errors['write']++;

      return true;
   }
   public function read (&$Socket) : bool
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
         #$this->log('Failed to read buffer: input data is empty!' . PHP_EOL, self::LOG_WARNING_LEVEL);
         // Server::$Event->del($Socket, Server::$Event::EVENT_WRITE);
         $this->Connection->close($Socket);
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

      // @ Write/Decode Data
      if (Server::$Application) {
         Server::$Application::decode($Socket, $this);
      }

      // TODO implement this data write by default?
      #Server::$Event->add($Socket, Server::$Event::EVENT_WRITE, 'write');
      self::write($Socket);

      return true;
   }
   public function write (&$Socket, ? int $length = null) : bool
   {
      // @ Set Output
      if (Server::$Application) {
         self::$output = Server::$Application::encode($Socket, $this);
      } else {
         self::$output = (SAPI::$Handler)(...$this->callbacks);
      }

      try {
         $buffer = self::$output;

         while (true) {
            $written = @fwrite($Socket, $buffer, $length);

            if ($written === false)
               break;

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
         return $this->fail($Socket, 'write', $written);
      }

      // @ On success
      #Server::$Event->del($Socket, Server::$Event::EVENT_WRITE);

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

   public function reject ($Socket, string $raw)
   {
      self::$output = $raw;

      try {
         @fwrite($Socket, self::$output);
      } catch (\Throwable) {}

      $this->Connection->close($Socket);
   }
}
