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
use function get_resource_type;
use function is_resource;
use function stream_socket_recvfrom;
use function stream_socket_sendto;
use function strlen;
use Throwable;

use Bootgly\ACI\Logs\Logger;
use Bootgly\WPI;
use Bootgly\WPI\Interfaces\UDP_Client_CLI as Client;
use Bootgly\WPI\Interfaces\UDP_Client_CLI\Connections;
use Bootgly\WPI\Interfaces\UDP_Client_CLI\Connections\Connection;


class Packages implements WPI\Connections\Packages
{
   public Logger $Logger {
      get {
         if ( isSet($this->Logger) === false ) {
            $this->Logger = new Logger(channel: static::class);
         }

         return $this->Logger;
      }
   }

   // * Config
   // ...

   // * Data
   // # IO
   public string $output;
   public string $input;

   // * Metadata
   public int $written;
   public int $read;
   // # Stats
   public int $writes;
   public int $reads;
   /** @var array<string,int> */
   public array $errors;
   // # Expiration
   public bool $expired;

   public Connection $Connection;


   public function __construct (Connection &$Connection)
   {
      $this->Connection = $Connection;

      // * Config
      // ...

      // * Data
      // # IO
      $this->output = '';
      $this->input = '';

      // * Metadata
      $this->written = 0;         // Output datagram length (bytes sent).
      $this->read = 0;            // Input datagram length (bytes received).
      // # Stats
      $this->writes = 0;          // Socket Write count
      $this->reads = 0;           // Socket Read count
      $this->errors['write'] = 0; // Socket Writing errors
      $this->errors['read'] = 0;  // Socket Reading errors
      // # Expiration
      $this->expired = false;
   }

   /**
    * Handle failed package operation.
    *
    * @param resource $Socket
    * @param string $operation
    * @param mixed $result
    *
    * @return bool
    */
   public function fail ($Socket, string $operation, mixed $result): bool
   {
      // UDP has no end-of-stream — we only close if the socket itself
      // has vanished (e.g. explicit fclose from somewhere else).
      if (is_resource($Socket) === false || get_resource_type($Socket) !== 'stream') {
         $this->Logger->log(
            warning: 'Failed to ' . $operation . ' datagram: socket gone, closing connection...' . PHP_EOL
         );

         $this->Connection->close();
      }
      else {
         $this->Logger->log(
            warning: 'Failed to ' . $operation . ' datagram.' . PHP_EOL
         );
      }

      Connections::$errors[$operation]++;

      return false;
   }
   /**
    * Send the queued datagram to the server.
    *
    * The output buffer is authoritative and datagrams are atomic: a
    * successful send consumes the whole buffer and drops the EVENT_WRITE
    * registration — arm it AFTER queueing `output`; one arm delivers one
    * datagram. A failed send keeps buffer and registration, so the next
    * write-ready wakeup retries.
    *
    * @param resource $Socket
    * @param null|int<0, max> $length
    *
    * @return bool
    */
   public function writing (&$Socket, null|int $length = null): bool
   {
      $buffer = $this->output;

      // ? Nothing queued — a spurious write-ready wakeup delivers nothing.
      //   Drop the stale registration: the reactor is level-triggered and a
      //   datagram socket is essentially always writable, so keeping it
      //   would redispatch this no-op forever.
      if ($buffer === '') {
         if ( isSet(Client::$Event) ) {
            try {
               Client::$Event->del($Socket, Client::$Event::EVENT_WRITE);
            }
            catch (Throwable) {}
         }

         return true;
      }

      $expected = strlen($buffer);

      try {
         // @ The client socket is "connected" (bound to remote peer),
         //   so sendto with no explicit address uses that peer.
         $sent = @stream_socket_sendto($Socket, $buffer);
      }
      catch (Throwable) {
         $sent = false;
      }

      // ? Failed — buffer and registration stay untouched for the retry.
      if ($sent === false || $sent < 0) {
         return $this->fail($Socket, 'write', $sent);
      }

      // @ UDP is lossy by design: short writes just get logged.
      if ($sent !== $expected) {
         $this->Logger->log(
            warning: "Short datagram send: {$sent} of {$expected} bytes." . PHP_EOL
         );
      }

      // @ Consume the datagram — atomic; the buffer never re-sends.
      $this->output = '';
      $this->written = $sent;

      // @ Set Stats
      if (Connections::$stats) {
         // Global
         Connections::$writes++;
         Connections::$written += $sent;
         // Per client
         $this->Connection->writes++;
      }

      // ? One datagram per arm: drop the registration BEFORE the hook so a
      //   hook that re-arms is not silently disarmed right after.
      if ( isSet(Client::$Event) ) {
         try {
            Client::$Event->del($Socket, Client::$Event::EVENT_WRITE);
         }
         catch (Throwable) {}
      }

      // # Hook
      if (Client::$onDatagramWrite) {
         (Client::$onDatagramWrite)($Socket, $this->Connection, $this);
      }

      return true;
   }
   /**
    * Receive one datagram from the server.
    *
    * @param resource $Socket
    * @param null|int<1,max> $length
    * @param null|int<0,max> $timeout
    *
    * @return bool
    */
   public function reading (
      &$Socket, null|int $length = null, null|int $timeout = null
   ): bool
   {
      try {
         $buffer = @stream_socket_recvfrom($Socket, $length ?? 65535);
      }
      catch (Throwable) {
         $buffer = false;
      }

      // @ Check issues
      if ($buffer === false) {
         return $this->fail($Socket, 'read', $buffer);
      }

      // @ Empty datagram: no data available right now.
      if ($buffer === '') {
         return false;
      }

      $received = strlen($buffer);

      // @ Set Input
      $this->input = $buffer;
      $this->read = $received;

      // @ Set Stats (disable to max performance in benchmarks)
      if (Connections::$stats) {
         // Global
         Connections::$reads++;
         Connections::$read += $received;
      }

      // # Hook
      if (Client::$onDatagramRead) {
         (Client::$onDatagramRead)($Socket, $this->Connection, $this);
      }

      return true;
   }

   public function write (&$Socket, null|int $length = null): bool
   {
      return false;
   }
   public function read (&$Socket): void
   {}
}
