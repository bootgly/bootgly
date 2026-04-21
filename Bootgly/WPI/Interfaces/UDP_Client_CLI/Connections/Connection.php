<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\UDP_Client_CLI\Connections;


use function fclose;
use function stream_socket_get_name;
use function time;
use Throwable;

use Bootgly\ACI\Events\Timer;
use Bootgly\WPI\Connections\Peer;
use Bootgly\WPI\Interfaces\UDP_Client_CLI as Client;
use Bootgly\WPI\Interfaces\UDP_Client_CLI\Connections;
use Bootgly\WPI\Interfaces\UDP_Client_CLI\Packages;


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
   public int $writes;


   /**
    * @param resource $Socket
    */
   public function __construct (&$Socket)
   {
      $this->Socket = $Socket;


      // * Config
      $this->timers = [];
      $this->expiration = 30; // UDP flows tend to be longer-lived than TCP ones

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
      $peer = stream_socket_get_name($Socket, true);
      if ($peer === false) {
         $this->close();
         return;
      }
      // @ Remote
      [$this->address, $this->port] = Peer::parse($peer);


      parent::__construct($this);

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
