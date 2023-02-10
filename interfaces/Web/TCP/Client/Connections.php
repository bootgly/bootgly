<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Web\TCP\Client;


use Bootgly\Web;
use Bootgly\Web\Packages; // @interface

use Bootgly\CLI\_\ {
   Logger\Logging // @trait
};

use Bootgly\Web\TCP\Client;
use Bootgly\Web\TCP\Client\Connections\Connection;


class Connections implements Web\Connections
{
   use Logging;


   public ? Client $Client;

   // * Config
   public ? float $timeout;
   public bool $async;
   public bool $blocking;
   // * Data
   public $Socket;
   // * Meta
   // @ Error
   public array $error = [];
   // @ Local
   public static array $Connections;
   // @ Stats
   public static bool $stats;
   // Connections
   public int $connections;
   // Errors
   public static array $errors;
   // Packages
   public static int $writes;
   public static int $reads;
   public static int $written;
   public static int $read;

   public Packages $Packages;


   public function __construct (? Client &$Client = null)
   {
      $this->Client = $Client;

      // * Config
      $this->timeout = 5;
      $this->async = true;
      $this->blocking = false;
      // * Data
      // ... dynamicaly
      // * Meta
      // @ Error
      $this->error = [];
      // @ Remote
      self::$Connections = []; // Connections peers
      // @ Stats
      self::$stats = true;
      // Connections
      $this->connections = 0;  // Connections count
      // Errors
      self::$errors = [
         'connection' => 0,    // Socket Connection errors
         'write' => 0,         // Socket Writing errors
         'read' => 0           // Socket Reading errors
         // 'except' => 0
      ];
      // Packages
      self::$writes = 0;       // Socket Write count
      self::$reads = 0;        // Socket Read count
      self::$written = 0;      // Socket Writes in bytes
      self::$read = 0;         // Socket Reads in bytes
   }
   public function __get ($name)
   {}

   // Open connection with server / Connect with server
   public function connect () : bool
   {
      Client::$Event->del($this->Client->Socket, Client::$Event::EVENT_CONNECT);

      try {
         // @ Set blocking
         stream_set_blocking($this->Client->Socket, $this->blocking);

         // @ Import stream
         if (function_exists('socket_import_stream') === true) {
            $Socket = socket_import_stream($this->Client->Socket);

            socket_set_option($Socket, SOL_SOCKET, SO_KEEPALIVE, 1);
            socket_set_option($Socket, SOL_TCP, TCP_NODELAY, 1);
         } else {
            $Socket = $this->Client->Socket;
         }
      } catch (\Throwable) {
         $Socket = false;
      }

      if ($Socket === false || is_resource($Socket) === false) {
         $this->log('Socket connection is false or invalid!' . PHP_EOL, self::LOG_ERROR_LEVEL);
         self::$errors['connection']++;
         return false;
      }

      // @ Instance new connection
      $Connection = new Connection($Socket);

      // @ Set stats
      $this->connections++;

      // @ Set Connection
      self::$Connections[(int) $Socket] = $Connection;

      // @ Add Connection Data read to Event loop
      Client::$Event->add($Socket, Client::$Event::EVENT_WRITE, $Connection);

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
