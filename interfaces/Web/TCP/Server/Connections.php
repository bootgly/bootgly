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


use Bootgly\OS\Process\Timer;

use Bootgly\Web;
use Bootgly\Web\Packages; // @interface

use Bootgly\CLI\_\ {
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
   public $Socket;
   // * Meta
   // @ Remote
   public static array $Connections;
   // @ Limiter
   public static array $blacklist;
   // @ Stats
   public static bool $stats;
   public int $connections;
   // Errors
   public static array $errors;
   // Data
   public static int $reads;
   public static int $writes;
   public static int $read;
   public static int $written;

   public Packages $Data;


   public function __construct (? Server &$Server = null, $Socket = null)
   {
      $this->Server = $Server;

      // * Config
      $this->timeout = 5;
      // * Data
      $this->Socket = $Socket;
      // * Meta
      // @ Remote
      self::$Connections = []; // Connections peers
      // @ Limiter
      self::$blacklist = [];   // Connections blacklist defined by limit methods
      // @ Stats
      self::$stats = true;
      $this->connections = 0;  // Connections count
      // Errors
      self::$errors = [
         'connection' => 0,  // Socket Connection errors
         'read' => 0,        // Socket Reading errors
         'write' => 0        // Socket Writing errors
         // 'except' => 0
      ];
      // Data
      self::$reads = 0;        // Socket Read count
      self::$writes = 0;       // Socket Write count
      self::$read = 0;         // Socket Reads in bytes
      self::$written = 0;      // Socket Writes in bytes
   }
   public function __get ($name)
   {
      require __DIR__ . '/Connections/@/info.php';
   }

   // Accept connection from client / Open connection with client / Connect with client
   public function accept ($Socket)
   {
      try {
         $Connection = @stream_socket_accept($Socket, $this->timeout);

         stream_set_blocking($Connection, false); // +15% performance

         #stream_set_read_buffer($Connection, 65535);
         #stream_set_write_buffer($Connection, 65535);
      } catch (\Throwable) {
         $Connection = false;
      }

      if ($Connection === false) {
         #$this->log('Socket connection is false!' . PHP_EOL);
         self::$errors['connection']++;
         return false;
      }

      // @ On success
      $Peer = new Connection($Connection);

      // @ Check connection
      if ( $Peer->check() === false )
         return false;

      // @ Set stats
      // Global
      $this->connections++;

      // @ Set Connection
      self::$Connections[(int) $Connection] = $Peer;

      // TODO call handler event $this->On->accept here
      // $this->On->accept($Connection);
      Server::$Event->add($Connection, Server::$Event::EVENT_READ, 'read');
      // TODO implement this data write by default?
      #Server::$Event->add($Connection, Server::$Event::EVENT_WRITE, 'write');

      return true;
   }

   public function close ($Connection)
   {
      $closed = self::$Connections[(int) $Connection]->close();

      // @ On success
      if ($closed) {
         // Remove closed connection from @peers
         #unset(self::$Connections[(int) $Connection]);

         return true;
      }

      return false;
   }
}