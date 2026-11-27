<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\UDP_Client_CLI;


use const PHP_EOL;
use function fclose;
use function is_resource;
use function stream_set_blocking;
use function stream_set_read_buffer;
use Throwable;

use Bootgly\ACI\Logs\Logger;
use Bootgly\WPI;
use Bootgly\WPI\Connections\Packages;
use Bootgly\WPI\Interfaces\UDP_Client_CLI as Client;
use Bootgly\WPI\Interfaces\UDP_Client_CLI\Connections\Connection;


/**
 * Flow registry of a UDP client.
 *
 * Legacy clients share the static registry and counters (`$Connections`,
 * `$stats`, `$errors`, `$writes`, `$reads`, `$written`, `$read`); an embedded
 * client (`MODE_EMBEDDED`) keeps its flows in `$Flows` and counts on the
 * instance, and never reads or resets a static.
 */
class Connections implements WPI\Connections
{
   // * Config
   public null|Client $Client;

   public null|float $timeout;
   public bool $async;
   public bool $blocking;

   // * Data
   public Logger $Logger {
      get {
         if ( isSet($this->Logger) === false ) {
            $this->Logger = new Logger(channel: static::class);
         }

         return $this->Logger;
      }
   }
   /** @var resource */
   public $Socket;

   // * Metadata
   // @ Error
   /** @var array<string> */
   public array $error = [];
   // @ Local
   /**
    * @deprecated 1.4.0 Process-wide registry shared by every legacy UDP client. 2.0 keeps the registry on the instance, as `MODE_EMBEDDED` already does (`$Flows`, renamed `$Connections`).
    *
    * @var array<int,Connection>
    */
   public static array $Connections;
   /**
    * Flows of an embedded client (`MODE_EMBEDDED`), keyed by `(int) $Socket`.
    * Removed by `release()` when a flow closes.
    *
    * @var array<int,Connection>
    */
   public private(set) array $Flows = [];
   // @ Stats
   /** @deprecated 1.4.0 Process-wide switch of the legacy counters. An embedded client always counts, per flow (`Packages`) and here (`$failures`). */
   public static bool $stats;
   // Connections
   public int $connections;
   /** Flows an embedded client failed to open (socket invalid or peer unknown). */
   public private(set) int $failures = 0;
   // Errors
   /**
    * @deprecated 1.4.0 Process-wide counters shared by every legacy UDP client. An embedded client counts per flow (`Packages->$errors`).
    *
    * @var array<string,int>
    */
   public static array $errors;
   // Packages
   /** @deprecated 1.4.0 Process-wide counter shared by every legacy UDP client. An embedded client counts per flow (`Packages->$writes`). */
   public static int $writes;
   /** @deprecated 1.4.0 Process-wide counter shared by every legacy UDP client. An embedded client counts per flow (`Packages->$reads`). */
   public static int $reads;
   /** @deprecated 1.4.0 Process-wide counter shared by every legacy UDP client. */
   public static int $written;
   /** @deprecated 1.4.0 Process-wide counter shared by every legacy UDP client. */
   public static int $read;

   public Packages $Packages;


   public function __construct (null|Client &$Client = null)
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
      // Connections
      $this->connections = 0;  // Connections count

      // ? An embedded client never resets the process-wide registry and
      //   counters other clients of the process share
      if ($Client?->embedded === true) {
         return;
      }

      // @ Remote
      self::$Connections = []; // Connections peers
      // @ Stats
      self::$stats = false;
      // Errors
      self::$errors = [
         'connection' => 0,    // Socket Connection errors
         'write' => 0,         // Socket Writing errors
         'read' => 0           // Socket Reading errors
      ];
      // Packages
      self::$writes = 0;       // Socket Write count
      self::$reads = 0;        // Socket Read count
      self::$written = 0;      // Socket Writes in bytes
      self::$read = 0;         // Socket Reads in bytes
   }

   /**
    * Bind the client socket and instantiate its Connection.
    *
    * UDP has no handshake and no connect step on the wire — the socket is
    * simply associated with the remote peer by the kernel. All that's left
    * here is to set non-blocking mode and register the Connection.
    */
   public function connect (): bool
   {
      // ? Embedded: the instance registry
      if ($this->Client?->embedded === true) {
         return $this->open($this->Client);
      }

      $Socket = &$this->Client->Socket;

      try {
         // @ Set blocking
         stream_set_blocking($Socket, $this->blocking);

         // @ Set Buffer sizes
         stream_set_read_buffer($Socket, 0);
      }
      catch (\Throwable) {
         $Socket = false;
      }

      if ($Socket === false || is_resource($Socket) === false) {
         $this->Logger->log(error: 'Socket is false or invalid!' . PHP_EOL);
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
    * Register the last socket of an embedded client as a new flow.
    *
    * The socket is read by value: a hook that dials again rebinds the client's
    * `$Socket`, never this registration. A flow whose peer is unknown closed
    * itself in its constructor and is not registered; a connect hook that
    * throws closes the socket, counts a failure and propagates.
    */
   private function open (Client $Client): bool
   {
      $Socket = $Client->Socket;

      try {
         // @ Set blocking
         stream_set_blocking($Socket, $this->blocking);

         // @ Set Buffer sizes
         stream_set_read_buffer($Socket, 0);
      }
      catch (Throwable) {
         $Socket = false;
      }

      if ($Socket === false || is_resource($Socket) === false) {
         $this->Logger->log(error: 'Socket is false or invalid!' . PHP_EOL);
         $this->failures++;
         return false;
      }

      // @ Instance new connection (its connect hook runs here)
      try {
         $Connection = new Connection($Socket, $Client);
      }
      catch (Throwable $Throwable) {
         // ? A connect hook that throws: the unregistered socket goes with it,
         //   and so do the registrations the hook may have armed on it
         $Reactor = $Client->Reactor;
         if ($Reactor !== null) {
            try {
               $Reactor->del($Socket, $Reactor::EVENT_WRITE);
               $Reactor->del($Socket, $Reactor::EVENT_READ);
            }
            catch (Throwable) {
               // ...
            }
         }
         try {
            @fclose($Socket);
         }
         catch (Throwable) {
            // ...
         }
         $this->failures++;

         throw $Throwable;
      }
      if ($Connection->status !== Connection::STATUS_ESTABLISHED) {
         $this->failures++;
         return false;
      }

      // @ Set stats
      $this->connections++;

      // @ Set Connection
      $this->Flows[(int) $Socket] = $Connection;

      return true;
   }

   /**
    * Remove a closed flow from the embedded registry.
    *
    * Only the very flow registered under its id is removed: another
    * Connection over the same socket (a stale or foreign one) never evicts
    * the registered flow.
    */
   public function release (Connection $Connection): void
   {
      if (($this->Flows[$Connection->id] ?? null) === $Connection) {
         unset($this->Flows[$Connection->id]);
      }
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
      $connection = (int) $Connection;

      // ? Embedded: the instance registry
      if ($this->Client?->embedded === true) {
         return isSet($this->Flows[$connection])
            ? $this->Flows[$connection]->close()
            : false;
      }

      if ( isSet(self::$Connections[$connection]) ) {
         $closed = self::$Connections[$connection]->close();
      }
      else {
         $closed = false;
      }

      if ($closed) {
         return true;
      }

      return false;
   }
}
