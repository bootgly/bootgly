<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
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

use Bootgly\ACI\Logs\LoggableEscaped;
use Bootgly\WPI;
use Bootgly\WPI\Interfaces\UDP_Client_CLI as Client;
use Bootgly\WPI\Interfaces\UDP_Client_CLI\Connections;
use Bootgly\WPI\Interfaces\UDP_Client_CLI\Connections\Connection;


class Packages implements WPI\Connections\Packages
{
   use LoggableEscaped;

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
         $this->log(
            'Failed to ' . $operation . ' datagram: socket gone, closing connection...' . PHP_EOL,
            self::LOG_WARNING_LEVEL
         );

         $this->Connection->close();
      }
      else {
         $this->log(
            'Failed to ' . $operation . ' datagram.' . PHP_EOL,
            self::LOG_WARNING_LEVEL
         );
      }

      Connections::$errors[$operation]++;

      return false;
   }
   /**
    * Send a datagram to the server.
    *
    * Datagrams are atomic — no partial-write retry loop.
    *
    * @param resource $Socket
    * @param null|int<0, max> $length
    *
    * @return bool
    */
   public function writing (&$Socket, null|int $length = null): bool
   {
      $buffer = $this->output;
      $expected = strlen($buffer);

      try {
         // @ The client socket is "connected" (bound to remote peer),
         //   so sendto with no explicit address uses that peer.
         $sent = @stream_socket_sendto($Socket, $buffer);
      }
      catch (Throwable) {
         $sent = false;
      }

      // @ Check issues
      if ($sent === false || $sent < 0) {
         return $this->fail($Socket, 'write', $sent);
      }

      // @ UDP is lossy by design: short writes just get logged.
      if ($sent !== $expected) {
         $this->log(
            "Short datagram send: {$sent} of {$expected} bytes." . PHP_EOL,
            self::LOG_WARNING_LEVEL
         );
      }

      // @ Set Stats
      if (Connections::$stats) {
         // Global
         Connections::$writes++;
         Connections::$written += $sent;
         // Per client
         if ( isSet(Connections::$Connections[(int) $Socket]) ) {
            Connections::$Connections[(int) $Socket]->writes++;
         }
      }

      // # Hook
      if (Client::$onWrite) {
         (Client::$onWrite)($Socket, $this->Connection, $this);
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
      if (Client::$onRead) {
         (Client::$onRead)($Socket, $this->Connection, $this);
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
