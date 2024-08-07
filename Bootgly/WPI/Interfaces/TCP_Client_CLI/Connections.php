<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\TCP_Client_CLI;


use Bootgly\ACI\Logs\LoggableEscaped;

use Bootgly\WPI;
use Bootgly\WPI\Connections\Packages; // @interface
use Bootgly\WPI\Interfaces\TCP_Client_CLI as Client;
use Bootgly\WPI\Interfaces\TCP_Client_CLI\Connections\Connection;


// FIXME: extends Connections
class Connections implements WPI\Connections
{
   use LoggableEscaped;


   public ? Client $Client;

   // * Config
   public ? float $timeout;
   public bool $async;
   public bool $blocking;

   // * Data
   /** @var resource */
   public $Socket;

   // * Metadata
   // @ Error
   /** @var array<string> */
   public array $error = [];
   // @ Local
   /** @var array<int,Connection> */
   public static array $Connections;
   // @ Stats
   public static bool $stats;
   // Connections
   public int $connections;
   // Errors
   /** @var array<string,int> */
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

      // * Metadata
      // @ Error
      $this->error = [];
      // @ Remote
      self::$Connections = []; // Connections peers
      // @ Stats
      self::$stats = false;
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

   // Open connection with server / Connect with server
   public function connect (): bool
   {
      $Socket = &$this->Client->Socket;

      Client::$Event->del($Socket, Client::$Event::EVENT_CONNECT);

      try {
         // @ Set blocking
         stream_set_blocking($Socket, $this->blocking);

         // @ Set Buffer sizes
         #stream_set_read_buffer($Socket, 65535);
         #stream_set_write_buffer($Socket, 65535);

         // @ Set Chunk size
         #stream_set_chunk_size($Socket, 65535);

         // @ Import stream
         #if (function_exists('socket_import_stream') === true) {
         #   $Socket = socket_import_stream($Socket);

         #   socket_set_option($Socket, SOL_SOCKET, SO_KEEPALIVE, 1);
         #   socket_set_option($Socket, SOL_TCP, TCP_NODELAY, 1);
         #}
      }
      catch (\Throwable) {
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

      return true;
   }

   /**
    * Close connection with server / Disconnect from server
    * 
    * @param resource $Connection
    * 
    * @return bool
    */
   public function close ($Connection): bool
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
      }
      else {
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
