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


use Bootgly\Web;
use Bootgly\Web\Packages; // @interface

use Bootgly\CLI\Terminal\_\ {
   Logger\Logging // @trait
};

use Bootgly\Web\TCP\Server;
use Bootgly\Web\TCP\Server\Connections\Connection;


class Connections implements Web\Connections
{
   use Logging;


   public ? Server $Server;

   // * Config
   public ? float $timeout;

   // * Data
   // ...

   // * Meta
   // @ Remote
   public static array $Connections;
   // @ Limiter
   public static array $blacklist;
   // @ Stats
   public static bool $stats;
   // Connections
   public int $connections;
   // Errors
   public static array $errors;
   // Packages
   public static int $reads;
   public static int $writes;
   public static int $read;
   public static int $written;

   public Packages $Packages;


   public function __construct (? Server &$Server = null)
   {
      $this->Server = $Server;

      // * Config
      $this->timeout = 5;

      // * Data
      // ..

      // * Meta
      // @ Remote
      self::$Connections = []; // Connections peers
      // @ Limiter
      self::$blacklist = [];   // Connections blacklist defined by limit methods
      // @ Stats
      self::$stats = true;
      // Connections
      $this->connections = 0;  // Connections count
      // Errors
      self::$errors = [
         'connection' => 0,  // Socket Connection errors
         'read' => 0,        // Socket Reading errors
         'write' => 0        // Socket Writing errors
         // 'except' => 0
      ];
      // Packages
      self::$reads = 0;        // Socket Read count
      self::$writes = 0;       // Socket Write count
      self::$read = 0;         // Socket Reads in bytes
      self::$written = 0;      // Socket Writes in bytes
   }
   public function __get ($name)
   {
      $info = __DIR__ . '/Connections/@/info.php';

      // @ Clear cache of file info
      if ( function_exists('opcache_invalidate') ) {
         opcache_invalidate($info, true);
      }

      clearstatcache(false, $info);

      // @ Load file info
      try {
         require $info;
      } catch (\Throwable) {
         // ...
      }
   }

   // Accept connection from client / Open connection with client / Connect with client
   public function connect () : bool
   {
      try {
         $Socket = @stream_socket_accept($this->Server->Socket, null);

         stream_set_timeout($Socket, 0);

         stream_set_blocking($Socket, false); // +15% performance

         #stream_set_chunk_size($Socket, 65535);

         #stream_set_read_buffer($Socket, 65535);
         #stream_set_write_buffer($Socket, 65535);
      } catch (\Throwable) {
         $Socket = false;
      }

      if ($Socket === false) {
         #$this->log('Socket connection is false!' . PHP_EOL);
         self::$errors['connection']++;
         return false;
      }

      // @ Instance new connection
      $Connection = new Connection($Socket);

      // @ Check connection
      if ( $Connection->check() === false ) {
         return false;
      }

      // @ Set stats
      $this->connections++;

      // @ Set Connection
      self::$Connections[(int) $Socket] = $Connection;

      // @ Add Connection Data read to Event loop
      Server::$Event->add($Socket, Server::$Event::EVENT_READ, $Connection);
      #Server::$Event->add($Socket, Server::$Event::EVENT_WRITE, $Connection);

      return true;
   }

   public function close ($Connection) : bool
   {
      // @ Close all Connections
      #if ($Connection === null) {
      #   foreach(self::$Connections as $Connection) {
      #      $Connection->close();
      #   }

      #   return true;
      #}

      $connection = (int) $Connection;

      // @ Close specific Connection
      if ( isSet(self::$Connections[$connection]) ) {
         $closed = self::$Connections[$connection]->close();
      } else {
         $closed = false;
      }

      // @ On success
      if ($closed) {
         // Remove closed connection from @peers
         #unset(self::$Connections[$connection]);

         return true;
      }

      return false;
   }
}
