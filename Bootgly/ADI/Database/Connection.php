<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Database;


use const STREAM_CLIENT_ASYNC_CONNECT;
use const STREAM_CLIENT_CONNECT;
use const STREAM_CRYPTO_METHOD_TLS_CLIENT;
use function fclose;
use function is_resource;
use function stream_context_create;
use function stream_socket_enable_crypto;
use function stream_set_blocking;
use function stream_socket_client;
use InvalidArgumentException;
use RuntimeException;

use Bootgly\ACI\Events\Readiness;
use Bootgly\ADI\Database\Config;
use Bootgly\ADI\Database\Driver;
use Bootgly\ADI\Database\Connection\ConnectionStates;


/**
 * Database connection state holder.
 *
 * Protocol-specific clients attach non-blocking stream resources here and keep
 * transport state reusable by the per-worker pool.
 */
class Connection
{
   // * Config
   public Config $Config;

   // * Data
   /** @var resource|null */
   public private(set) mixed $socket = null;
   public private(set) bool $connected = false;
   public private(set) ConnectionStates $state = ConnectionStates::Idle;

   // * Metadata
   public private(set) null|Driver $Protocol = null;


   public function __construct (Config $Config)
   {
      // * Config
      $this->Config = $Config;
   }

   /**
    * Open a non-blocking TCP connection.
    */
   public function connect (float $deadline = 0.0): Readiness
   {
      $target = "tcp://{$this->Config->host}:{$this->Config->port}";
      $errorCode = 0;
      $error = '';
      $context = null;

      if ($this->Config->secure['mode'] !== Config::SECURE_DISABLE) {
         $context = stream_context_create([
            'ssl' => [
               'verify_peer' => $this->Config->secure['verify'],
               'verify_peer_name' => $this->Config->secure['name'],
               'peer_name' => $this->Config->secure['peer'],
               'cafile' => $this->Config->secure['cafile'],
               'SNI_enabled' => $this->Config->secure['peer'] !== '',
            ],
         ]);
      }
      $socket = @stream_socket_client(
         $target,
         $errorCode,
         $error,
         $this->Config->timeout,
         STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
         $context
      );

      if ($socket === false) {
         $message = $error !== '' ? $error : 'native stream returned false';

         throw new RuntimeException("Database connection failed: {$message}");
      }

      stream_set_blocking($socket, false);

      $this->socket = $socket;
      $this->connected = false;
      $this->state = ConnectionStates::Connecting;

      return Readiness::write($socket, $deadline);
   }

   /**
    * Progress TLS encryption on the attached stream.
    */
   public function encrypt (): null|bool
   {
      // ?
      if (is_resource($this->socket) === false) {
         throw new InvalidArgumentException('Connection socket must be ready before TLS encryption.');
      }

      $encrypted = @stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);

      if ($encrypted === true) {
         $this->state = ConnectionStates::Encrypted;

         return true;
      }

      if ($encrypted === 0) {
         return null;
      }

      return false;
   }

   /**
    * Attach a non-blocking stream resource to this connection.
    *
    * @param resource $socket
    */
   public function attach (mixed $socket): self
   {
      // ?
      if (is_resource($socket) === false) {
         throw new InvalidArgumentException('Connection socket must be a resource.');
      }

      // * Data
      $this->socket = $socket;
      $this->connected = true;
      $this->state = ConnectionStates::Ready;

      return $this;
   }

   /**
    * Transition the attached stream to a protocol state.
    */
   public function transition (ConnectionStates $state = ConnectionStates::Ready): self
   {
      // ?
      if (is_resource($this->socket) === false) {
         throw new InvalidArgumentException('Connection socket must be ready before state update.');
      }

      $this->connected = true;
      $this->state = $state;

      return $this;
   }

   /**
    * Bind a protocol instance to this connection.
    */
   public function bind (Driver $Protocol): self
   {
      $this->Protocol = $Protocol;

      return $this;
   }

   /**
    * Close the attached stream resource.
    */
   public function disconnect (): bool
   {
      if (is_resource($this->socket)) {
         fclose($this->socket);
      }

      $this->socket = null;
      $this->connected = false;
      $this->state = ConnectionStates::Idle;
      $this->Protocol = null;

      return true;
   }
}
