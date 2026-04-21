<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\UDP_Server_CLI;


use function explode;
use function str_starts_with;
use function substr;

use const Bootgly\CLI;
use Bootgly\ACI\Logs\LoggableEscaped;
use Bootgly\WPI;
use Bootgly\WPI\Interfaces\UDP_Server_CLI as Server;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Connections\Connection;


class Connections implements WPI\Connections
{
   use LoggableEscaped;


   public Server $Server;

   // * Config
   public null|float $timeout;

   // * Data
   // ...

   // * Metadata
   // @ Remote (peer string "ip:port" → Connection)
   /** @var array<string,Connection> */
   public static array $Connections;
   // @ Limiter
   /** @var array<string,bool> */
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

   // @ Event-loop payload — routes inbound datagrams to per-peer Connections.
   public Router $Router;


   public function __construct (Server &$Server)
   {
      $this->Server = $Server;

      // * Config
      $this->timeout = 5;

      // * Metadata
      // @ Remote
      self::$Connections = []; // Peer registry (keyed by "ip:port")
      // @ Limiter
      self::$blacklist = [];
      // @ Stats
      self::$stats = true;
      $this->connections = 0;
      // Errors
      self::$errors = [
         'connection' => 0,
         'read' => 0,
         'write' => 0
      ];
      // Packages
      self::$reads = 0;
      self::$writes = 0;
      self::$read = 0;
      self::$written = 0;

      // @ Router (shared server-socket datagram dispatcher)
      $this->Router = new Router($Server, $this);
   }
   public function __get (string $name): mixed
   {
      // Remove @ in name if exists (eg.: @connections -> connections)
      if (str_starts_with($name, '@')) {
         $name = substr($name, 1);
      }

      CLI->Commands->route([
         __CLASS__,
         ...explode(" ", $name)
      ], From: $this->Server);

      return null;
   }

   /**
    * UDP has no accept() step — datagrams are delivered directly on the
    * shared server socket. This method satisfies the `WPI\Connections`
    * contract but is never called by the event loop for UDP (the
    * listening socket is registered with EVENT_READ pointing at
    * `$this->Router`, not EVENT_CONNECT).
    */
   public function connect (): bool
   {
      return true;
   }

   /**
    * Resolve a peer address to its Connection, creating one if the peer
    * is new. Called by the Router on every inbound datagram.
    */
   public function accept (string $peer): null|Connection
   {
      if ( isSet(self::$Connections[$peer]) ) {
         return self::$Connections[$peer];
      }

      $Connection = new Connection($this->Server->Socket, $peer);

      if ( $Connection->check() === false ) {
         self::$errors['connection']++;
         return null;
      }

      $this->connections++;
      self::$Connections[$peer] = $Connection;

      return $Connection;
   }

   /**
    * Close a specific peer Connection.
    *
    * @param string $Connection "ip:port" peer key.
    *
    * @return bool
    */
   public function close ($Connection): bool
   {
      if ( isSet(self::$Connections[$Connection]) ) {
         return self::$Connections[$Connection]->close();
      }

      return false;
   }
}
