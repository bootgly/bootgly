<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\TCP_Client_CLI\Connections;


use const INF;
use const STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
use const STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
use function fclose;
use function hrtime;
use function max;
use function microtime;
use function min;
use function stream_select;
use function stream_set_blocking;
use function stream_socket_enable_crypto;
use function stream_socket_get_name;
use function time;
use Throwable;

use Bootgly\ACI\Events\Timer;
use Bootgly\WPI\Connections\Peer;
use Bootgly\WPI\Interfaces\TCP_Client_CLI as Client;
use Bootgly\WPI\Interfaces\TCP_Client_CLI\Connections;
use Bootgly\WPI\Interfaces\TCP_Client_CLI\Packages;


class Connection extends Packages
{
   /** @var resource */
   public $Socket;


   // * Config
   /** @var array<false|int> */
   public array $timers;
   public int $expiration;

   // * Data
   // # Owner (the TCP/WS client that opened this connection — dispatch back-ref).
   public null|Client $Client = null;
   // # Remote
   public string $address;
   public int $port;

   // * Metadata
   public int $id;
   public bool $encrypted;
   /** True only when the remote peer, rather than a local abort, ended the stream. */
   public bool $peerEOF;
   // # Status
   public const int STATUS_INITIAL = 0;
   public const int STATUS_CONNECTING = 1;
   public const int STATUS_ESTABLISHED = 2;
   public const int STATUS_CLOSING = 4;
   public const int STATUS_CLOSED = 8;
   public int $status;
   // # State
   public int $started;
   public int $used;
   // # Stats
   #public int $reads;
   public int $writes;


   /**
    * @param resource $Socket
   * @param bool $secure Whether secure SSL/TLS handshake is required
    */
   public function __construct (
      &$Socket,
      bool $secure = false,
      null|Client $Client = null,
      null|float $deadline = null,
      null|int $monotonicDeadline = null
   )
   {
      $this->Socket = $Socket;
      $this->Client = $Client;


      // * Config
      $this->timers = [];
      $this->expiration = 10;

      // * Data
      // ... dynamicaly

      // * Metadata
      $this->id = (int) $Socket;
      $this->encrypted = false;
      $this->peerEOF = false;
      // # Status
      $this->status = self::STATUS_ESTABLISHED;
      // # Handler
      $this->started = time();
      $this->used = time();
      // # Stats
      $this->writes = 0;
      $this->reads = 0;


      // @ Set Remote Data if possible
      // IP:port
      $peer = stream_socket_get_name($Socket, false);
      if ($peer === false) {
         $this->close();
         return;
      }
      // * Data
      // @ Remote
      [$this->address, $this->port] = Peer::parse($peer);


      parent::__construct($this);

      // @ Call handshake if secure transport is enabled
      if ($secure && $this->handshake($deadline, $monotonicDeadline) === false) {
         return;
      }

      // @ Call On Connection connect
      if (Client::$onClientConnect) {
         (Client::$onClientConnect)($Socket, $this);
      }
   }

   public function close (): true
   {
      if ($this->status > self::STATUS_ESTABLISHED) {
         return true;
      }

      $this->status = self::STATUS_CLOSING;

      Client::$Event->del($this->Socket, Client::$Event::EVENT_WRITE);
      Client::$Event->del($this->Socket, Client::$Event::EVENT_READ);

      try {
         @fclose($this->Socket);
      }
      catch (Throwable) {
         // ...
      }

      $this->status = self::STATUS_CLOSED;

      if (Client::$onClientDisconnect) {
         (Client::$onClientDisconnect)($this);
      }

      // @ Destroy itself
      unset(Connections::$Connections[$this->id]);

      return true;
   }

   public function handshake (
      null|float $deadline = null,
      null|int $monotonicDeadline = null
   ): bool|int
   {
      try {
         stream_set_blocking($this->Socket, false);
         do {
            if (
               ($deadline !== null && microtime(true) >= $deadline)
               || ($monotonicDeadline !== null && (int) hrtime(true) >= $monotonicDeadline)
            ) {
               $negotiation = false;
               break;
            }

            $negotiation = @stream_socket_enable_crypto(
               $this->Socket,
               true,
               STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT
            );
            if ($negotiation === true || $negotiation === false) {
               break;
            }

            do {
               $read = [$this->Socket];
               $write = [];
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
            } while (
               $selected === false
               && ($deadline === null || microtime(true) < $deadline)
               && ($monotonicDeadline === null || (int) hrtime(true) < $monotonicDeadline)
            );
            if ($selected !== 1) {
               $negotiation = false;
               break;
            }
         } while (true);
      }
      catch (Throwable) {
         $negotiation = false;
      }

      // @ Check negotiation
      if ($negotiation === false) {
         $this->close();
         return false;
      }
      else if ($negotiation === 0) {
         return 0;
      }

      $this->encrypted = true;

      return true;
   }

   public function __destruct ()
   {
      foreach ($this->timers as $id) {
         if ($id === false) {
            continue;
         }

         Timer::del($id);
      }
   }
}
