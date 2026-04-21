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


use function get_resource_type;
use function is_resource;
use function stream_socket_sendto;
use function strlen;
use Throwable;

use Bootgly\ACI\Logs\LoggableEscaped;
use Bootgly\ACI\Logs\Logger;
use Bootgly\API\Workables\Server as SAPI;
use Bootgly\WPI;
use Bootgly\WPI\Endpoints\Servers\Packages as Server_Packages;
use Bootgly\WPI\Interfaces\UDP_Server_CLI as Server;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Connections\Connection;


abstract class Packages extends Server_Packages implements WPI\Connections\Packages
{
   use LoggableEscaped;

   public Connection $Connection;


   public function __construct (Connection &$Connection)
   {
      $this->Logger = new Logger(channel: __CLASS__);
      $this->Connection = $Connection;

      parent::__construct();
   }

   /**
    * Fail to read/write data.
    *
    * UDP is connectionless: there is no EOF. Only mark the operation as
    * failed and increment stats — do not tear the per-peer Connection
    * down unless the shared socket resource itself went away.
    *
    * @param resource $Socket
    * @param string $operation
    *
    * @return bool
    */
   public function fail ($Socket, string $operation): bool
   {
      if (is_resource($Socket) === false || get_resource_type($Socket) !== 'stream') {
         Connections::$errors[$operation]++;
         $this->Connection->close();
         return true;
      }

      Connections::$errors[$operation]++;
      return false;
   }

   /**
    * Datagrams for this peer arrive via `Router::reading()`, which feeds
    * `$this->input` and invokes the decoder. The event loop never calls
    * this per-peer handler directly — it is kept only to satisfy the
    * `WPI\Connections\Packages` contract.
    *
    * @param resource $Socket
    * @param null|int $length
    * @param null|int $timeout
    *
    * @return bool
    */
   public function reading (
      &$Socket, null|int $length = null, null|int $timeout = null
   ): bool
   {
      return true;
   }

   /**
    * Encode output and send it as a single datagram to this peer.
    *
    * @param resource $Socket
    * @param null|int<0,max> $length
    *
    * @return bool
    */
   public function write (&$Socket, null|int $length = null): bool
   {
      // !
      $Encoder = $this->Encoder ?? Server::$Encoder;
      if ($Encoder) { // @ Encode Application Data if exists
         $buffer = $Encoder::encode($this, $length);
      }
      else {
         /** @var string $buffer */
         $buffer = (SAPI::$Handler)(...$this->callbacks);
      }

      // :
      return $this->writing($Socket, length: $length, buffer: $buffer);
   }
   /**
    * Send a datagram to this peer.
    *
    * UDP datagrams are atomic: `stream_socket_sendto()` either delivers
    * the whole buffer or none of it. Short writes signal a dropped
    * packet, not backpressure — there is no retry loop.
    *
    * @param resource $Socket
    * @param null|int<0,max> $length
    * @param string $buffer
    *
    * @return bool
    */
   public function writing (&$Socket, null|int $length = null, string $buffer = ''): bool
   {
      if ($buffer === '') {
         return true;
      }

      $length ??= strlen($buffer);

      try {
         $sent = @stream_socket_sendto($Socket, $buffer, 0, $this->Connection->peer);
      }
      catch (Throwable) {
         $sent = -1;
      }

      // @ Failure
      if ($sent === -1 || $sent === false) {
         return $this->fail($Socket, 'write');
      }

      // @ Set Stats
      if (Connections::$stats) {
         // Global
         Connections::$writes++;
         Connections::$written += $sent;
         // Per peer
         if ( isSet(Connections::$Connections[$this->Connection->peer]) ) {
            Connections::$Connections[$this->Connection->peer]->writes++;
         }
      }

      return $sent === $length;
   }
   public function read (&$Socket): void
   {
      // N/A
   }

   public function reject (string $raw): void
   {
      try {
         @stream_socket_sendto($this->Connection->Socket, $raw, 0, $this->Connection->peer);
      }
      catch (Throwable) {
         // ...
      }

      $this->Connection->close();
   }
}
