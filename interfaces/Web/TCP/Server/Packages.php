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
   public array $reading;
   public array $writing;
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
      $this->reading = [];
      $this->writing = [];
      $this->callbacks = [&self::$input];
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
         #$this->log('Failed to ' . $operation . ' package: 0 byte handled!' . PHP_EOL);
      }

      if (is_resource($Socket) && get_resource_type($Socket) === 'stream') {
         #$this->log('Failed to ' . $operation . ' package: closing connection...' . PHP_EOL);
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

      // $this->log($input);

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
      $length = strlen($input);

      if (Connections::$stats) {
         // Global
         Connections::$reads++;
         Connections::$read += $length;
         // Per client
         #Connections::$Connections[(int) $Socket]['reads']++;
      }

      // @ Write data
      if (Server::$Application) { // @ Decode Application Data if exists
         $length = Server::$Application::decode($this, $input, $length);
      }

      if ($length) {
         $this->write($Socket);
      }

      return true;
   }
   public function write (&$Socket, ? int $length = null) : bool
   {
      if (Server::$Application) {
         self::$output = Server::$Application::encode($this, $length);
      } else {
         self::$output = (SAPI::$Handler)(...$this->callbacks);
      }

      try {
         $buffer = self::$output;
         $written = 0;

         while ($buffer) {
            $sent = @fwrite($Socket, $buffer, $length);

            if ($sent === false) break;
            if ($sent === 0) continue; // TODO check EOF?

            $written += $sent;

            if ($sent < $length) {
               $buffer = substr($buffer, $sent);
               $length -= $sent;
               continue;
            }

            if ( count($this->writing) ) {
               $written += $this->writing($Socket);
            }

            break;
         }
      } catch (\Throwable) {
         $written = false;
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

      return true;
   }

   // ! Stream
   public function reading ($Socket) : int
   {
      // ! WARNING check server disk space
      // ! WARNING check user input length
      // TODO test!!!
      $handler = @fopen($this->reading[0]['file'], 'w+');
      $offset = 0;
      $length = $this->reading[0]['length'] ?? -1;
      $close = true;

      $received = 0;

      // @ Limit length of data file (size) to write
      $over = 0;
      $rate = 1 * 1024 * 1024; // 1 MB (1048576) = Max rate to write/receive data file by loop
      $size = $rate;

      if ($length > 0 && $length < $rate) {
         $size = $length;
      } else if ($length > $rate) {
         $over = $length - $rate;
      }

      while ($length < 0 || $received < $length) {
         // @ Read part of file from client
         try {
            $buffer = @fread($Socket, $size);
         } catch (\Throwable) {
            break;
         }
   
         if ($buffer === false) {
            break;
         }
   
         $read = strlen($buffer);
   
         // @ Write part of received (if exists) file to disk
         while ($read) {
            try {
               $written = @fwrite($handler, $buffer, $read);
            } catch (\Throwable) {
               break;
            }
   
            if ($written === false) {
               break;
            } else if ($written === 0) {
               continue;
            } else if ($written < $read) {
               $received += $written;
               $buffer = substr($buffer, $written);
               $read = strlen($buffer);
            } else {
               $received += $written;
               break;
            }
         }
   
         // @ Check End-of-file
         try {
            $end = @feof($Socket);
         } catch (\Throwable) {
            break;
         }
   
         if ($end) {
            break;
         }
   
         // @ Set new over / size if necessary
         if ($over % $rate > 0) {
            if ($over >= $received) {
               $over -= $received;
            }
   
            if ($over < $size) {
               $size = $over;
            }
         }
      }
   
      // @ Try closing the handler / socket if it's still open
      if ($close) {
         try {
            @fclose($handler);
            @fclose($Socket);
         } catch (\Throwable) {}
      }
   
      return $received;
   }
   public function writing ($Socket) : int
   {
      // TODO support to send multiple files

      $handler = @fopen($this->writing[0]['file'], 'r');

      $offset = $this->writing[0]['offset'];
      $length = $this->writing[0]['length'];
      $close = $this->writing[0]['close'];

      $written = 0;

      // @ Move pointer of file to offset
      try {
         @fseek($handler, $offset, SEEK_SET);
         #@flock($handler, \LOCK_SH);
      } catch (\Throwable) {
         return $written;
      }

      // @ Limit length of data file (size) to read
      $over = 0;
      $rate = 1 * 1024 * 1024; // 1 MB (1048576) = Max rate to read/send data file by loop
      $size = $rate;

      if ($length < $rate) {
         $size = $length;
      } else if ($length > $rate) {
         $over = $length - $rate;
      }

      while ($written < $length) {
         // @ Read file from disk
         try {
            $buffer = @fread($handler, $size);
         } catch (\Throwable) {
            break;
         }

         if ($buffer === false) {
            break;
         }

         $read = strlen($buffer);

         // @ Send part of read (if exists) file to client
         while ($read) {
            try {
               $sent = @fwrite($Socket, $buffer, $read);
            } catch (\Throwable) {
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

         // @ Check End-of-file
         try {
            $end = @feof($handler);
         } catch (\Throwable) {
            break;
         }

         if ($end) {
            break;
         }

         // @ Set new over / size if necessary
         if ($over % $rate > 0) {
            if ($over >= $written) {
               $over -= $written;
            }

            if ($over < $size) {
               $size = $over;
            }
         }
      }

      // @ Try closing the handler / socket if it's still open
      if ($close) {
         try {
            @fclose($handler);
   
            $this->Connection->close();
         } catch (\Throwable) {}
      }

      // @ Unset handler from writing
      unSet($this->writing[0]);

      return $written;
   }

   public function reject (string $raw)
   {
      try {
         @fwrite($this->Connection->Socket, $raw);
      } catch (\Throwable) {}

      $this->Connection->close();
   }
}
