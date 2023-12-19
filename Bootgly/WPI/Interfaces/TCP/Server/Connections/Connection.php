<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\TCP\Server\Connections;


use Bootgly\ACI\Events\Timer;
use Bootgly\WPI\Interfaces\TCP\Server;
use Bootgly\WPI\Interfaces\TCP\Server\Connections;
use Bootgly\WPI\Interfaces\TCP\Server\Packages;


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

   // * Metadata
   public readonly int $id;
   public bool $encrypted;
   public int $status;
   // @ State
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
      $this->expiration = 15;

      // * Data
      // ... dynamicaly

      // * Metadata
      $this->id = (int) $Socket;
      $this->encrypted = false;
      $this->status = Connections::STATUS_ESTABLISHED;
      // @ State
      $this->started = \time();
      $this->used = \time();
      // @ Stats
      #$this->reads = 0;
      $this->writes = 0;


      // @ Set Remote Data if possible
      // IP:port
      $peer = \stream_socket_get_name($Socket, true);
      if ($peer === false) {
         return $this->close();
      }
      // * Data
      // @ Remote
      @[$this->ip, $this->port] = \explode(':', $peer, 2); // TODO IPv6

      parent::__construct($this);

      // @ Call handshake if SSL is enabled
      if ( isSet(Server::$context['ssl']) && $this->handshake() === false) {
         return false;
      }

      if (Connections::$stats) {
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
            args: [1000]
         );
         */
      }
   }

   public function handshake ()
   {
      static $tries = 1;

      try {
         \stream_set_blocking($this->Socket, true);

         $negotiation = @\stream_socket_enable_crypto(
            $this->Socket,
            true,
            STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | STREAM_CRYPTO_METHOD_TLSv1_3_SERVER
         );

         \stream_set_blocking($this->Socket, false);
      }
      catch (\Throwable) {
         $negotiation = false;
      }

      // @ Check negotiation
      if ($negotiation === false) {
         $this->close();
         return false;
      }
      else if ($negotiation === 0) {
         if ($tries > 2) {
            return false;
         }

         $tries++;

         $this->handshake();

         return 0;
      }
      else {
         $this->encrypted = true;
      }

      return true;
   }

   public function check () : bool
   {
      // @ Check blacklist
      // Blocked IP
      if ( isSet(Connections::$blacklist[$this->ip]) ) {
         // TODO add timer to unblock
         return false;
      }

      return true;
   }
   public function expire (int $timeout)
   {
      if ($this->status > Connections::STATUS_ESTABLISHED) {
         return true;
      }

      static $writes = 0;

      if ($writes < $this->writes) {
         $this->used = \time();
      }
      if ($writes > $this->writes) {
         $writes = $this->writes;
         $this->used = \time();
      }

      if ((\time() - $this->used) >= $timeout) {
         return $this->close();
      }

      $writes = $this->writes;

      return false;
   }
   public function limit (int $packages)
   {
      if ($this->status > Connections::STATUS_ESTABLISHED) {
         return true;
      }

      static $writes = 0;

      if (($this->writes - $writes) >= $packages) {
         Connections::$blacklist[$this->ip] = true;
         return $this->close();
      }

      $writes = $this->writes;

      return false;
   }

   public function close () : true
   {
      if ($this->status > Connections::STATUS_ESTABLISHED) {
         return true;
      }

      $this->status = Connections::STATUS_CLOSING;

      $Socket = &$this->Socket;

      /*
      if ( isSet(Server::$context['ssl'] ) {
         try {
            stream_set_blocking($Socket, true);
            stream_socket_enable_crypto($Socket, false);
            stream_set_blocking($Socket, false);
         }
         catch (\Throwable) {}
      }
      */

      Server::$Event->del($Socket, Server::$Event::EVENT_READ);
      Server::$Event->del($Socket, Server::$Event::EVENT_WRITE);

      try {
         @\fclose($Socket);
      }
      catch (\Throwable) {
         // ...
      }

      $this->status = Connections::STATUS_CLOSED;

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
