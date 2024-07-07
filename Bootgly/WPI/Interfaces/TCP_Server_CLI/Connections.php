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


use Bootgly\ACI\Logs\LoggableEscaped;

use const Bootgly\CLI;

use Bootgly\WPI;
use Bootgly\WPI\Connections\Packages; // @interface
use Bootgly\WPI\Interfaces\TCP_Server_CLI as Server;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;


// FIXME: extends Connections
class Connections implements WPI\Connections
{
   use LoggableEscaped;


   public ? Server $Server;

   // * Config
   public ? float $timeout;

   // * Data
   // ...

   // * Metadata
   // @ Remote
   /** @var array<int,Connection> */
   public static array $Connections;
   // @ Limiter
   /** @var array<string> */
   public static array $blacklist;
   // @ Stats
   public static bool $stats;
   // Connections
   public int $connections;
   // Errors
   /** @var array<string,int> */
   public static array $errors;
   // Packages
   public static int $reads;
   public static int $writes;
   public static int $read;
   public static int $written;
   // @ Status
   public const STATUS_INITIAL = 0;
   public const STATUS_CONNECTING = 1;
   public const STATUS_ESTABLISHED = 2;
   public const STATUS_CLOSING = 4;
   public const STATUS_CLOSED = 8;

   public Packages $Packages;


   public function __construct (? Server &$Server = null)
   {
      $this->Server = $Server;

      // * Config
      $this->timeout = 5;

      // * Data
      // ..

      // * Metadata
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
   public function __get (string $name): mixed
   {
      // Remove @ in name if exists (eg.: @connections -> connections)
      if (\str_starts_with($name, '@')) {
         $name = \substr($name, 1);
      }

      CLI->Commands->route(command: [
         __CLASS__,
         ...explode(" ", $name)
      ], From: $this->Server);

      return null;
   }

   // Accept connection from client / Open connection with client / Connect with client
   public function connect (): bool
   {
      try {
         $Socket = @\stream_socket_accept($this->Server->Socket, null);

         \stream_set_timeout($Socket, 0);

         \stream_set_blocking($Socket, false); // +15% performance

         #stream_set_chunk_size($Socket, 65535);

         #stream_set_read_buffer($Socket, 65535);
         #stream_set_write_buffer($Socket, 65535);
      }
      catch (\Throwable) {
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

   /**
    * Close connection with client
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
