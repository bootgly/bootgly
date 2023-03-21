<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Web\TCP\Client\Connections;


use Bootgly\OS\Process\Timer;
use Bootgly\Web\TCP\Client;
use Bootgly\Web\TCP\Client\Connections;
use Bootgly\Web\TCP\Client\Packages;


class Connection extends Packages
{
   public $Socket;


   // * Config
   public array $timers;
   public int $expiration;

   // * Data
   // @ Remote
   public string $ip;
   public int $port;

   // * Meta
   public int $id;
   // @ Status
   public const STATUS_INITIAL = 0;
   public const STATUS_CONNECTING = 1;
   public const STATUS_ESTABLISHED = 2;
   public const STATUS_CLOSING = 4;
   public const STATUS_CLOSED = 8;
   public int $status;
   // @ Handler
   public int $started;
   public int $used;
   // @ Stats
   #public int $reads;
   public int $writes;


   public function __construct (&$Socket)
   {
      $this->Socket = $Socket;


      // * Config
      $this->timers = [];
      $this->expiration = 10;

      // * Data
      // ... dynamicaly

      // * Meta
      $this->id = (int) $Socket;
      // @ Status
      $this->status = self::STATUS_ESTABLISHED;
      // @ Handler
      $this->started = time();
      $this->used = time();
      // @ Stats
      $this->writes = 0;
      $this->reads = 0;


      // @ Set Remote Data if possible
      // IP:port
      $peer = stream_socket_get_name($Socket, false);
      if ($peer === false) {
         return $this->close();
      }
      // * Data
      // @ Remote
      @[$this->ip, $this->port] = explode(':', $peer, 2); // TODO IPv6

      parent::__construct($this);

      // @ Call On Connection connect
      if (Client::$onConnect) {
         (Client::$onConnect)($Socket, $this);
      }
   }

   public function close () : true
   {
      if ($this->status > self::STATUS_ESTABLISHED) {
         return true;
      }

      $this->status = self::STATUS_CLOSING;

      Client::$Event->del($this->Socket, Client::$Event::EVENT_WRITE);
      Client::$Event->del($this->Socket, Client::$Event::EVENT_READ);

      try {
         @fclose($this->Socket);
      } catch (\Throwable) {
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
         Timer::del($id);
      }
   }
}
