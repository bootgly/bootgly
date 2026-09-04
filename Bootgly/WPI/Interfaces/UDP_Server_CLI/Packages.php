<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\UDP_Server_CLI;


use function get_resource_type;
use function is_resource;
use function stream_socket_sendto;
use function strlen;
use Throwable;

use Bootgly\ACI\Logs\Logger;
use Bootgly\API\Workables\Server as SAPI;
use Bootgly\WPI;
use Bootgly\WPI\Endpoints\Servers\Packages as Server_Packages;
use Bootgly\WPI\Interfaces\UDP_Server_CLI as Server;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Connections\Connection\Authority as ConnectionAuthority;


abstract class Packages extends Server_Packages implements WPI\Connections\Packages
{
   // * Config
   /**
    * Public per-peer Connection view.
    */
   public Connection $Connection;

   // * Data
   // ? Lazy: constructed on first read only. The transport hot path logs
   //   nothing, so an eager per-peer Logger graph is pure allocation churn
   //   under peer churn (mirrors TCP_Server_CLI\Packages).
   public Logger $Logger {
      get {
         if ( isSet($this->Logger) === false ) {
            $this->Logger = new Logger(channel: static::class, global: true);
         }

         return $this->Logger;
      }
   }

   /** @param Connection $Connection Per-peer connection exposed by this Package. */
   public function __construct (Connection &$Connection)
   {
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
      $invalid = is_resource($Socket) === false || get_resource_type($Socket) !== 'stream';
      if (
         $this instanceof Connection
         && ConnectionAuthority::check($this) === false
      ) {
         return true;
      }
      if ($this instanceof Connection) {
         $owned = false;
         $Connection = $this;
      }
      else {
         try {
            $owned = isSet($this->Connection); // @phpstan-ignore isset.initializedProperty
            if ($owned) {
               $Connection = $this->Connection;
            }
         }
         catch (Throwable) {
            return true;
         }
         if ($owned === false) {
            if ($invalid) {
               return true;
            }

            Connections::$errors[$operation]++;
            return false;
         }
      }
      if (
         $this instanceof Connection
         && ConnectionAuthority::check($this) === false
      ) {
         return true;
      }
      if (ConnectionAuthority::check($Connection) === false) {
         if ($invalid) {
            $Connection->close();
         }
         return true;
      }
      if ($owned) {
         try {
            $CurrentConnection = $this->Connection;
         }
         catch (Throwable) {
            if ($invalid) {
               $Connection->close();
            }
            return true;
         }
         if (
            $this instanceof Connection
            && ConnectionAuthority::check($this) === false
         ) {
            return true;
         }
         if ($CurrentConnection !== $Connection) {
            return true;
         }
         if (ConnectionAuthority::check($Connection) === false) { // @phpstan-ignore identical.alwaysFalse
            if ($invalid) {
               $Connection->close();
            }
            return true;
         }
      }

      Connections::$errors[$operation]++;
      if ($invalid) {
         $Connection->close();
         return true;
      }

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
      if (
         $this instanceof Connection
         && ConnectionAuthority::check($this) === false
      ) {
         return false;
      }
      if ($this instanceof Connection) {
         $owned = false;
         $Connection = $this;
      }
      else {
         try {
            $Connection = $this->Connection;
            $owned = true;
         }
         catch (Throwable) {
            return false;
         }
      }
      if (
         $this instanceof Connection
         && ConnectionAuthority::check($this) === false
      ) {
         return false;
      }
      if (ConnectionAuthority::check($Connection) === false) {
         return false;
      }
      try {
         $Encoder = $this->Encoder ?? Server::$Encoder;
      }
      catch (Throwable) {
         return false;
      }
      if (
         $this instanceof Connection
         && ConnectionAuthority::check($this) === false
      ) {
         return false;
      }
      if ($owned) {
         try {
            $CurrentConnection = $this->Connection;
         }
         catch (Throwable) {
            return false;
         }
         if ($CurrentConnection !== $Connection) {
            return false;
         }
      }
      if (ConnectionAuthority::check($Connection) === false) { // @phpstan-ignore identical.alwaysFalse
         return false;
      }
      if ($Encoder) { // @ Encode Application Data if exists
         $buffer = $Encoder::encode($this, $length);
      }
      else {
         /** @var string $buffer */
         $buffer = (SAPI::$Handler)(...$this->callbacks);
      }

      // ? A decoder/encoder/application callback may close its peer. The
      //   logical terminal transition wins; never enter writing() through an
      //   ownership property that close() has already released.
      if (ConnectionAuthority::check($Connection) === false) { // @phpstan-ignore identical.alwaysFalse
         return false;
      }
      if ($owned) {
         try {
            $CurrentConnection = $this->Connection;
         }
         catch (Throwable) {
            return false;
         }
         if ($CurrentConnection !== $Connection) {
            return false;
         }
         if (ConnectionAuthority::check($Connection) === false) { // @phpstan-ignore identical.alwaysFalse
            return false;
         }
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

      if (
         $this instanceof Connection
         && ConnectionAuthority::check($this) === false
      ) {
         return false;
      }

      if ($this instanceof Connection) {
         $owned = false;
         $Connection = $this;
      }
      else {
         try {
            $Connection = $this->Connection;
            $owned = true;
         }
         catch (Throwable) {
            return false;
         }
      }
      if (
         $this instanceof Connection
         && ConnectionAuthority::check($this) === false
      ) {
         return false;
      }
      if (ConnectionAuthority::check($Connection) === false) {
         return false;
      }

      $length ??= strlen($buffer);
      try {
         // ! Network authority is bound to the immutable admission key. The
         //   public compatibility field may be mutated or hooked, but it can
         //   never retarget a privately authorized datagram.
         $peer = $Connection->id;
      }
      catch (Throwable) {
         return false;
      }
      if (ConnectionAuthority::check($Connection) === false) { // @phpstan-ignore identical.alwaysFalse
         return false;
      }
      if ($owned) {
         try {
            $CurrentConnection = $this->Connection;
         }
         catch (Throwable) {
            return false;
         }
         if ($CurrentConnection !== $Connection) {
            return false;
         }
         if (ConnectionAuthority::check($Connection) === false) { // @phpstan-ignore identical.alwaysFalse
            return false;
         }
      }

      try {
         $sent = @stream_socket_sendto($Socket, $buffer, 0, $peer);
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
         if ((Connections::$Connections[$peer] ?? null) === $Connection) {
            $Connection->writes++;
         }
      }

      return $sent === $length;
   }
   public function read (&$Socket): void
   {
      // N/A
   }

   /**
    * Send a terminal rejection datagram to the exact current peer.
    *
    * @param string $raw Datagram payload.
    *
    * @return void
    */
   public function reject (string $raw): void
   {
      if (
         $this instanceof Connection
         && ConnectionAuthority::check($this) === false
      ) {
         $this->close();
         return;
      }
      if ($this instanceof Connection) {
         $owned = false;
         $Connection = $this;
      }
      else {
         try {
            $Connection = $this->Connection;
            $owned = true;
         }
         catch (Throwable) {
            return;
         }
      }
      if (
         $this instanceof Connection
         && ConnectionAuthority::check($this) === false
      ) {
         $this->close();
         return;
      }
      if (ConnectionAuthority::check($Connection) === false) {
         $Connection->close();
         return;
      }
      try {
         $TargetSocket = $Connection->Socket;
      }
      catch (Throwable) {
         $Connection->close();
         return;
      }
      if (
         $this instanceof Connection
         && ConnectionAuthority::check($this) === false
      ) {
         $this->close();
         return;
      }
      if (ConnectionAuthority::check($Connection) === false) { // @phpstan-ignore identical.alwaysFalse
         $Connection->close();
         return;
      }
      try {
         $peer = $Connection->id;
      }
      catch (Throwable) {
         $Connection->close();
         return;
      }
      if (
         $this instanceof Connection
         && ConnectionAuthority::check($this) === false
      ) {
         $this->close();
         return;
      }
      if (ConnectionAuthority::check($Connection) === false) { // @phpstan-ignore identical.alwaysFalse
         $Connection->close();
         return;
      }
      if ($owned) {
         try {
            $CurrentConnection = $this->Connection;
         }
         catch (Throwable) {
            $Connection->close();
            return;
         }
         if ($CurrentConnection !== $Connection) {
            return;
         }
         if (ConnectionAuthority::check($Connection) === false) { // @phpstan-ignore identical.alwaysFalse
            $Connection->close();
            return;
         }
      }
      try {
         @stream_socket_sendto($TargetSocket, $raw, 0, $peer);
      }
      catch (Throwable) {
         // Terminal ownership still closes below after a transport failure.
      }

      $Connection->close();
   }
}
