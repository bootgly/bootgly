<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\TCP_Server_CLI;

#use const PHP_EOL;


use function count;
use function explode;
use function fclose;
use function is_resource;
use function max;
use function str_starts_with;
use function stream_set_blocking;
use function stream_set_read_buffer;
use function stream_set_timeout;
use function stream_socket_accept;
use function stream_socket_get_name;
use function substr;
use Throwable;

use const Bootgly\CLI;
use Bootgly\ACI\Logs\Logger;
use Bootgly\WPI;
use Bootgly\WPI\Connections\Packages;
use Bootgly\WPI\Connections\Peer;
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
   //   Declaration defaults mirror the constructor: transport code (e.g.
   //   `Packages::reading()`, `Packages::fail()`) reads these statics
   //   unconditionally, so they must be readable in processes that never
   //   construct a server (socketless tests, tooling).
   /** @var array<int,Connection> */
   public static array $Connections = [];
   // @ Limiter
   /** @var array<string,bool> */
   public static array $blacklist = [];
   /** @var array<string,int> Live admitted-connection count per peer IP (audit F-2). */
   public static array $ipConnections = [];
   /** Live admitted TLS connections that have not completed their handshake. */
   public static int $pendingHandshakes = 0;
   // @ Stats
   public static bool $stats = false;
   // Connections
   public int $connections;
   // Errors
   /** @var array<string,int> */
   public static array $errors = [
      'connection' => 0,
      'read' => 0,
      'write' => 0
   ];
   // Packages
   public static int $reads = 0;
   public static int $writes = 0;
   public static int $read = 0;
   public static int $written = 0;
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
      self::$ipConnections = []; // Live admitted-connection count per peer IP
      self::$pendingHandshakes = 0;
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
      $Socket = false;

      try {
         /** @var resource|false $Socket */
         $Socket = @stream_socket_accept($this->Server->Socket, null);
         if ($Socket === false) {
            self::$errors['connection']++;

            return false;
         }

         stream_set_timeout($Socket, 0);

         stream_set_blocking($Socket, false); // +15% performance

         stream_set_read_buffer($Socket, 0);

         #stream_set_chunk_size($Socket, 65535);

         #stream_set_read_buffer($Socket, 65535);
         #stream_set_write_buffer($Socket, 65535);
      }
      catch (Throwable) {
         if (is_resource($Socket)) {
            @fclose($Socket);
         }

         self::$errors['connection']++;

         return false;
      }

      // ! Resolve the kernel-owned peer identity before constructing a
      //   Connection. A reset socket can still be returned by accept() after
      //   getpeername has become unavailable; no partially initialized object
      //   may escape into blacklist, limiter, TLS, or event-loop paths.
      $peer = @stream_socket_get_name($Socket, true);
      if ($peer === false) {
         self::$errors['connection']++;
         @fclose($Socket);

         return false;
      }

      [$IP, $port] = Peer::parse($peer);
      if ($IP === '' || $port < 1 || $port > 65_535) {
         self::$errors['connection']++;
         @fclose($Socket);

         return false;
      }

      try {
         // @ Instance only a fully identified connection.
         $Connection = new Connection($Socket, $IP, $port);
      }
      catch (Throwable) {
         self::$errors['connection']++;
         @fclose($Socket);

         return false;
      }

      // @ Check connection
      if ( $Connection->check() === false ) {
         self::$errors['connection']++;
         $Connection->close();
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

      // ? Pending TLS ceiling: apply after peer/global admission but before
      //   any crypto work. The fail-closed minimum prevents a direct static
      //   assignment of zero/negative values from silently disabling the
      //   unauthenticated-handshake bound.
      if (
         $Connection->handshaking
         && self::$pendingHandshakes >= max(1, Server::$maxPendingHandshakes)
      ) {
         self::$errors['connection']++;
         $Connection->close();
         return false;
      }

      // @ Set stats
      $this->connections++;

      // @ Set Connection
      self::$Connections[(int) $Socket] = $Connection;
      // @ Track all admitted peers before crypto (balanced by close()).
      self::$ipConnections[$Connection->ip] =
         (self::$ipConnections[$Connection->ip] ?? 0) + 1;
      if ($Connection->handshaking) {
         self::$pendingHandshakes++;
      }

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
