<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Web\TCP\Server\Connections;


use Bootgly\OS\Process\Timer;
use Bootgly\Web\TCP\Server;
use Bootgly\Web\TCP\Server\Connections;


class Connection # extends Data
{
   public $Socket;

   // * Config
   public array $timers;
   public int $expiration;
   // * Data
   public string $ip;
   public int $port;
   // * Meta
   // @ status
   const STATUS_INITIAL = 0;
   const STATUS_CONNECTING = 1;
   const STATUS_ESTABLISHED = 2;
   const STATUS_CLOSING = 4;
   const STATUS_CLOSED = 8;
   public int $status;
   // @ handling
   public int $started;
   public int $used;
   // @ stats
   #public int $reads;
   public int $writes;


   public function __construct (&$Socket, string $ip, int $port)
   {
      $this->Socket = $Socket;

      // * Config
      $this->timers = [];
      $this->expiration = 15;
      // * Data
      $this->ip = $ip;
      $this->port = $port;
      // * Meta
      $this->status = self::STATUS_ESTABLISHED;
      // @ handled
      $this->started = time();
      $this->used = time();
      // @ stats
      #$this->reads = 0;
      $this->writes = 0;

      // @ Set Connection timeout expiration
      $this->timers[] = Timer::add(
         interval: $this->expiration,
         handler: [$this, 'expire'],
         args: [$this->expiration]
      );
      /*
      // @ Set Connection limit
      $this->timers[] = Timer::add(
         interval: 5,
         handler: [$this, 'limit'],
         args: [6]
      );
      */
   }

   public function expire (int $timeout = 5) 
   {
      static $writes = 0;

      if ($this->status === self::STATUS_CLOSED) {
         return true;
      }

      if ($writes < $this->writes) {
         $this->used = time();
      }

      if (time() - $this->used >= $timeout) {
         return $this->close();
      }

      $writes = $this->writes;

      return false;
   }
   public function limit (int $packages)
   {
      static $writes = 0;

      if ($this->status === self::STATUS_CLOSED) {
         return true;
      }

      if (($this->writes - $writes) >= $packages) {
         Connections::$blacklist[$this->ip] = true;
         return $this->close();
      }

      $writes = $this->writes;

      return false;
   }

   public function close ()
   {
      Server::$Event->del($this->Socket, Server::$Event::EVENT_READ);
      Server::$Event->del($this->Socket, Server::$Event::EVENT_WRITE);

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
      // Delete timers
      foreach ($this->timers as $id) {
         Timer::del($id);
      }
      // Destroy itself
      unset(Connections::$Connections[(int) $this->Socket]);

      return true;
   }
}
