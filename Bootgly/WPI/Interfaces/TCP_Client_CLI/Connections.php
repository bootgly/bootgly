<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\TCP_Client_CLI;


use const INF;
use const PHP_EOL;
use function fclose;
use function hrtime;
use function is_resource;
use function max;
use function microtime;
use function min;
use function stream_select;
use function stream_set_blocking;
use function stream_set_read_buffer;
use function stream_socket_get_name;

use Bootgly\ACI\Logs\Logger;
use Bootgly\WPI;
use Bootgly\WPI\Connections\Packages;
use Bootgly\WPI\Interfaces\TCP_Client_CLI as Client;
use Bootgly\WPI\Interfaces\TCP_Client_CLI\Connections\Connection;


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


   public null|Client $Client;

   // * Config
   public null|float $timeout;
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
   public array $Connections;
   // @ Stats
   public bool $stats;
   // Connections
   public int $connections;
   // Errors
   /** @var array<string,int> */
   public array $errors;
   // Packages
   public int $writes;
   public int $reads;
   public int $written;
   public int $read;

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
      // @ Remote
      $this->Connections = []; // Connections peers
      // @ Stats
      $this->stats = false;
      // Connections
      $this->connections = 0;  // Connections count
      // Errors
      $this->errors = [
         'connection' => 0,    // Socket Connection errors
         'write' => 0,         // Socket Writing errors
         'read' => 0           // Socket Reading errors
         // 'except' => 0
      ];
      // Packages
      $this->writes = 0;       // Socket Write count
      $this->reads = 0;        // Socket Read count
      $this->written = 0;      // Socket Writes in bytes
      $this->read = 0;         // Socket Reads in bytes
   }

   // Open connection with server / Connect with server
   public function connect (): bool
   {
      $Client = $this->Client;
      if ($Client === null) {
         $this->errors['connection']++;
         return false;
      }
      $Socket = &$Client->Socket;

      $Client->Event->del($Socket, $Client->Event::EVENT_CONNECT);

      try {
         // @ Set blocking
         stream_set_blocking($Socket, $this->blocking);

         // @ Set Buffer sizes
         stream_set_read_buffer($Socket, 0);
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
         $this->Logger->log(error: 'Socket connection is false or invalid!' . PHP_EOL);
         $this->errors['connection']++;
         return false;
      }

      // ! ASYNC_CONNECT only creates the socket. Do not construct a logical
      // connection (or begin TLS) until TCP is writable and the peer name is
      // available. One absolute deadline covers this wait and the handshake.
      $now = microtime(true);
      $nowMonotonic = (int) hrtime(true);
      $deadline = $Client->deadline;
      $monotonicDeadline = $Client->monotonicDeadline;
      if ($Client->connectTimeout > 0) {
         $connectDeadline = $now + $Client->connectTimeout;
         $connectMonotonicDeadline = $nowMonotonic
            + (int) ($Client->connectTimeout * 1_000_000_000);
         $deadline = $deadline === null
            ? $connectDeadline
            : min($deadline, $connectDeadline);
         $monotonicDeadline = $monotonicDeadline === null
            ? $connectMonotonicDeadline
            : min($monotonicDeadline, $connectMonotonicDeadline);
      }
      if (
         ($deadline !== null && $deadline <= $now)
         || ($monotonicDeadline !== null && $monotonicDeadline <= $nowMonotonic)
      ) {
         fclose($Socket);
         $this->errors['connection']++;
         return false;
      }

      do {
         $read = [];
         $write = [$Socket];
         $except = null;
         if ($deadline === null && $monotonicDeadline === null) {
            $selected = @stream_select($read, $write, $except, null);
         }
         else {
            $remaining = $deadline === null
               ? INF
               : max(0.0, $deadline - microtime(true));
            if ($monotonicDeadline !== null) {
               $remaining = min(
                  $remaining,
                  max(0.0, ($monotonicDeadline - (int) hrtime(true)) / 1_000_000_000)
               );
            }
            $seconds = (int) $remaining;
            $microseconds = (int) (($remaining - $seconds) * 1_000_000);
            $selected = @stream_select($read, $write, $except, $seconds, $microseconds);
         }
         // SIGALRM parent watchdogs legitimately interrupt select. Retry only
         // while a finite caller deadline still bounds a persistent failure.
      } while (
         $selected === false
         && ($deadline === null || microtime(true) < $deadline)
         && ($monotonicDeadline === null || (int) hrtime(true) < $monotonicDeadline)
      );
      if (
         $selected !== 1
         || @stream_socket_get_name($Socket, true) === false
      ) {
         fclose($Socket);
         $this->errors['connection']++;
         return false;
      }

      // @ Instance new connection
      $secure = $Client->secure !== null;
      $Connection = new Connection(
         $Socket,
         $secure,
         $Client,
         $deadline,
         $monotonicDeadline
      );
      if ($Connection->status !== Connection::STATUS_ESTABLISHED || ($secure && $Connection->encrypted === false)) {
         $this->errors['connection']++;
         return false;
      }

      // @ Set stats
      $this->connections++;

      // @ Set Connection
      $this->Connections[(int) $Socket] = $Connection;

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
      if ( isSet($this->Connections[$connection]) ) {
         $closed = $this->Connections[$connection]->close();
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
