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


class Connection
{
   public $Socket;

   // * Config
   public array $timers;
   public int $expiration;
   // * Data
   public string $peer;
   // * Meta
   // @ status
   public string $status;
   // @ handling
   public int $started;
   public int $used;
   // @ stats
   public int $reads;
   public int $writes;


   public function __construct (&$Socket, $peer)
   {
      $this->Socket = $Socket;

      // * Config
      $this->timers = [];
      $this->expiration = 15;
      // * Data
      $this->peer = $peer;
      // * Meta
      $this->status = 'opened';
      // @ handled
      $this->started = time();
      $this->used = time();
      // @ stats
      $this->reads = 0;
      $this->writes = 0;

      // @ Set Connection timeout expiration
      $this->timers[] = Timer::add(
         interval: $this->expiration,
         handler: [$this, 'expire'],
         args: [$this->expiration]
      );
   }

   public function expire (int $timeout = 5) 
   {
      static $writes = 0;

      if ($this->status === 'closed') {
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
      $this->status = 'closed';
      // Delete timers
      foreach ($this->timers as $id) {
         Timer::del($id);
      }
      // Destroy itself
      unset(Connections::$Connections[(int) $this->Socket]);

      return true;
   }
}
