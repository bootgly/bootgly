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

#use const PHP_EOL;


use function count;
use function explode;
use function str_starts_with;
use function stream_set_blocking;
use function stream_set_read_buffer;
use function stream_set_timeout;
use function stream_socket_accept;
use function substr;
use Throwable;

use const Bootgly\CLI;
use Bootgly\ACI\Logs\Logger;
use Bootgly\WPI;
use Bootgly\WPI\Connections\Packages;
use Bootgly\WPI\Interfaces\TCP_Server_CLI as Server;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;


class Connections implements WPI\Connections
{
   public Logger $Logger {
      get {
         if ( isSet($this->Logger) === false ) {
            $this->Logger = new Logger(channel: static::class);
         }

         return $this->Logger;
      }
   }


   public Server $Server;

   // * Config
   public null|float $timeout;

   // * Data
   // ...

   // * Metadata
   // @ Remote
   /** @var array<int,Connection> */
   public static array $Connections;
   // @ Limiter
   /** @var array<string,bool> */
   public static array $blacklist;
   /** @var array<string,int> Live established-connection count per peer IP (audit F-2). */
   public static array $ipConnections;
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
   public const int STATUS_INITIAL = 0;
   public const int STATUS_CONNECTING = 1;
   public const int STATUS_ESTABLISHED = 2;
   public const int STATUS_CLOSING = 4;
   public const int STATUS_CLOSED = 8;

   public Packages $Packages;


   public function __construct (Server &$Server)
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
      self::$ipConnections = []; // Live established-connection count per peer IP
      // @ Stats
      // ! Off by default: 4 static increments per request, only consumed by
      //   the `stats` command — which lazily enables collection when first
      //   invoked. Connection idle expiration does NOT depend on this flag
      //   (it is gated by `Connection->expiration` and fed by the always-on
      //   `Connection->writes` counter).
      self::$stats = false;
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
    * May a new connection from `$ip` be admitted under the configured
    * concurrency ceilings? (audit F-2)
    *
    * Returns false when accepting it would reach the global ceiling
    * (`Server::$maxConnections`) or this peer's per-IP ceiling
    * (`Server::$maxConnectionsPerIP`, opt-in). Either ceiling is disabled by a
    * 0 value. Mirrors `Connection::check()` (true = proceed) and is isolated so
    * it is unit-testable without a live socket. Consulted once per accept —
    * never on the per-request hot path.
    */
   public static function check (string $ip): bool
   {
      // ? Global ceiling.
      if (
         Server::$maxConnections > 0
         && count(self::$Connections) >= Server::$maxConnections
      ) {
         return false;
      }

      // ? Per-IP ceiling (opt-in; 0 = unlimited, reverse-proxy-safe default).
      if (
         Server::$maxConnectionsPerIP > 0
         && (self::$ipConnections[$ip] ?? 0) >= Server::$maxConnectionsPerIP
      ) {
         return false;
      }

      return true;
   }

   // Accept connection from client / Open connection with client / Connect with client
   public function connect (): bool
   {
      try {
         /** @var resource $Socket */
         $Socket = @stream_socket_accept($this->Server->Socket, null);

         stream_set_timeout($Socket, 0);

         stream_set_blocking($Socket, false); // +15% performance

         stream_set_read_buffer($Socket, 0);

         #stream_set_chunk_size($Socket, 65535);

         #stream_set_read_buffer($Socket, 65535);
         #stream_set_write_buffer($Socket, 65535);
      }
      catch (Throwable) {
         $Socket = false;
      }

      if ($Socket === false) {
         #$this->Logger->log(debug: 'Socket connection is false!' . PHP_EOL);
         self::$errors['connection']++;
         return false;
      }

      // @ Instance new connection
      $Connection = new Connection($Socket);

      // @ Check connection
      if ( $Connection->check() === false ) {
         return false;
      }

      // ? Concurrency ceilings (audit F-2): shed when the global or this peer's
      //   per-IP limit is reached, bounding FD/memory under a connection flood.
      //   `check()` is once-per-accept; the per-request hot path is untouched.
      if ( self::check($Connection->ip) === false ) {
         self::$errors['connection']++;
         $Connection->close();
         return false;
      }

      // @ Set stats
      $this->connections++;

      // @ Set Connection
      self::$Connections[(int) $Socket] = $Connection;
      // @ Track live per-IP count (balanced by Connection::close()).
      self::$ipConnections[$Connection->ip] =
         (self::$ipConnections[$Connection->ip] ?? 0) + 1;

      // @ Add Connection Data read to Event loop
      $added = Server::$Event->add($Socket, Server::$Event::EVENT_READ, $Connection);
      #Server::$Event->add($Socket, Server::$Event::EVENT_WRITE, $Connection);

      // ? Event loop full (select FD ceiling): shed the connection — an accepted
      //   socket that is never registered would stay ESTABLISHED and unread forever
      if ($added === false) {
         self::$errors['connection']++;
         $this->close($Socket);

         return false;
      }

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
