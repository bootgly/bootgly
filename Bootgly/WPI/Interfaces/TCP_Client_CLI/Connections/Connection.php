<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\TCP_Client_CLI\Connections;


use const STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
use const STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
use function explode;
use function fclose;
use function stream_set_blocking;
use function stream_socket_enable_crypto;
use function stream_socket_get_name;
use function time;
use Throwable;

use Bootgly\ACI\Events\Timer;
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
   // # Remote
   public string $address;
   public int $port;

   // * Metadata
   public int $id;
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
    * @param bool $ssl Whether SSL/TLS handshake is required
    */
   public function __construct (&$Socket, bool $ssl = false)
   {
      $this->Socket = $Socket;


      // * Config
      $this->timers = [];
      $this->expiration = 10;

      // * Data
      // ... dynamicaly

      // * Metadata
      $this->id = (int) $Socket;
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
      @[$IP, $port] = explode(':', $peer, 2); // TODO IPv6
      $this->address = $IP;
      $this->port = (int) $port;


      parent::__construct($this);

      // @ Call handshake if SSL is enabled
      if ($ssl && $this->handshake() === false) {
         return;
      }

      // @ Call On Connection connect
      if (Client::$onConnect) {
         (Client::$onConnect)($Socket, $this);
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

      if (Client::$onDisconnect) {
         (Client::$onDisconnect)($this);
      }

      // @ Destroy itself
      unset(Connections::$Connections[$this->id]);

      return true;
   }

   public function handshake (): bool|int
   {
      try {
         stream_set_blocking($this->Socket, true);

         $negotiation = @stream_socket_enable_crypto(
            $this->Socket,
            true,
            STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT
         );

         stream_set_blocking($this->Socket, false);
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
