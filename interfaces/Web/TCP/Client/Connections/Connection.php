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
use Bootgly\Web\_\Events\Select;
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

      // @ Remote
      // IP:port
      $peer = stream_socket_get_name($Socket, true);
      @[$ip, $port] = explode(':', $peer, 2); // TODO IPv6
      // @ Local?
      // metadata
      #$metadata = stream_get_meta_data($Socket);

      // * Config
      $this->timers = [];
      $this->expiration = 10;
      // * Data
      $this->ip = $ip;
      $this->port = $port;
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

      parent::__construct($this);

      // @ Call On Connection connect
      if (Client::$Connect) {
         (Client::$Connect)($this);
      }
   }

   public function close ()
   {
      Client::$Event->del($this->Socket, Client::$Event::EVENT_WRITE);
      Client::$Event->del($this->Socket, Client::$Event::EVENT_READ);

      if ($this->Socket === null || $this->Socket === false) {
         #$this->log('$Socket is false or null on close!');
         return false;
      }

      $closed = false;
      try {
         $closed = @fclose($this->Socket);
      } catch (\Throwable) {}

      if ($closed === false) {
         #$this->log('Connection failed to close!' . PHP_EOL);
         return false;
      }

      // @ On success
      $this->status = self::STATUS_CLOSED;
      // @ Delete timers
      foreach ($this->timers as $id) {
         Timer::del($id);
      }
      // @ Destroy itself
      unset(Connections::$Connections[$this->id]);

      return true;
   }

   public function __destruct ()
   {
      // @ Delete timers
      foreach ($this->timers as $id) {
         Timer::del($id);
      }
   }
}
