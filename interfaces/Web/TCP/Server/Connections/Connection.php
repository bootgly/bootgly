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
use Bootgly\Web\TCP\Server\Packages;


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
      $this->expiration = 15;

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
      #$this->reads = 0;
      $this->writes = 0;


      // @ Set Remote Data if possible
      // IP:port
      $peer = stream_socket_get_name($Socket, true);
      if ($peer === false) {
         return $this->close();
      }
      // * Data
      // @ Remote
      @[$this->ip, $this->port] = explode(':', $peer, 2); // TODO IPv6

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
         stream_set_blocking($this->Socket, true);

         $negotiation = @stream_socket_enable_crypto(
            $this->Socket,
            true,
            STREAM_CRYPTO_METHOD_TLSv1_2_SERVER | STREAM_CRYPTO_METHOD_TLSv1_3_SERVER
         );

         stream_set_blocking($this->Socket, false);
      } catch (\Throwable) {
         $negotiation = false;
      }

      // @ Check negotiation
      if ($negotiation === false) {
         $this->close();
         return false;
      } elseif ($negotiation === 0) {
         if ($tries > 2) {
            return false;
         }

         $tries++;

         $this->handshake();

         return 0;
      } else {
         // Handshake success!
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
      static $writes = 0;

      if ($this->status > self::STATUS_ESTABLISHED) {
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

      if ($this->status > self::STATUS_ESTABLISHED) {
         return true;
      }

      if (($this->writes - $writes) >= $packages) {
         Connections::$blacklist[$this->ip] = true;
         return $this->close();
      }

      $writes = $this->writes;

      return false;
   }

   public function close () : true
   {
      if ($this->status > self::STATUS_ESTABLISHED) {
         return true;
      }

      $this->status = self::STATUS_CLOSING;

      $Socket = &$this->Socket;

      /*
      if ( isSet(Server::$context['ssl'] ) {
         try {
            stream_set_blocking($this->Socket, true);
            stream_socket_enable_crypto($Socket, false);
            stream_set_blocking($this->Socket, false);
         } catch (\Throwable) {}
      }
      */

      Server::$Event->del($Socket, Server::$Event::EVENT_READ);
      #Server::$Event->del($Socket, Server::$Event::EVENT_WRITE);

      try {
         @fclose($Socket);
         #@stream_socket_shutdown($Socket);
      } catch (\Throwable) {
         // ...
      }

      $this->status = self::STATUS_CLOSED;

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
