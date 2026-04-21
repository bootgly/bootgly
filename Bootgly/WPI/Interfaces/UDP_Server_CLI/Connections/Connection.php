<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\UDP_Server_CLI\Connections;


use function strpos;
use function strrpos;
use function substr;
use function time;

use Bootgly\ACI\Events\Timer;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\UDP_Server_CLI\Packages;


class Connection extends Packages
{
   /** @var resource */
   public $Socket;

   // * Config
   /** @var array<int> */
   public array $timers;
   public int $expiration;

   // * Data
   // @ Remote
   public string $peer;
   public string $ip;
   public int $port;

   // * Metadata
   public readonly string $id;
   public bool $encrypted;
   public int $status;
   // @ State
   public int $started;
   public int $used;
   // @ Stats
   public int $writes;


   /**
    * @param resource $Socket Shared UDP server socket.
    * @param string $peer "ip:port" peer address (IPv4 or "[ip]:port" for IPv6).
    */
   public function __construct (&$Socket, string $peer)
   {
      $this->Socket = $Socket;

      // * Config
      $this->timers = [];
      $this->expiration = 30;

      // * Data
      // @ Remote — IPv6-aware: "[::1]:1234" unwraps to "::1" so $ip
      //   matches the canonical unbracketed literal used by blacklist,
      //   TrustedProxy, and RateLimit. IPv4 splits on the last ':'.
      $this->peer = $peer;
      if (isset($peer[0]) && $peer[0] === '[') {
         $rb = strpos($peer, ']');
         if ($rb !== false) {
            $this->ip = substr($peer, 1, $rb - 1);
            $this->port = (int) substr($peer, $rb + 2);
         }
         else {
            $this->ip = $peer;
            $this->port = 0;
         }
      }
      else {
         $separator = strrpos($peer, ':');
         if ($separator === false) {
            $this->ip = $peer;
            $this->port = 0;
         }
         else {
            $this->ip = substr($peer, 0, $separator);
            $this->port = (int) substr($peer, $separator + 1);
         }
      }

      // * Metadata
      $this->id = $peer;
      $this->encrypted = false;
      $this->status = Connections::STATUS_ESTABLISHED;
      // @ State
      $this->started = time();
      $this->used = time();
      // @ Stats
      $this->writes = 0;


      parent::__construct($this);

      if (Connections::$stats) {
         $timer = Timer::add(
            interval: $this->expiration,
            handler: [$this, 'expire'],
            args: [$this->expiration]
         );

         if ($timer) {
            $this->timers[] = $timer;
         }
      }
   }

   public function check (): bool
   {
      // @ Check blacklist
      if ( isSet(Connections::$blacklist[$this->ip]) ) {
         return false;
      }

      return true;
   }
   public function expire (int $timeout): bool
   {
      if ($this->status > Connections::STATUS_ESTABLISHED) {
         return true;
      }

      static $writes = 0;

      if ($writes < $this->writes) {
         $this->used = time();
      }
      if ($writes > $this->writes) {
         $writes = $this->writes;
         $this->used = time();
      }

      if ((time() - $this->used) >= $timeout) {
         return $this->close();
      }

      $writes = $this->writes;

      return false;
   }
   public function limit (int $packages): bool
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

   public function close (): true
   {
      if ($this->status > Connections::STATUS_ESTABLISHED) {
         return true;
      }

      $this->status = Connections::STATUS_CLOSING;

      // The underlying socket is shared by the server with every peer —
      // do not unregister it from the event loop and do not fclose().

      $this->status = Connections::STATUS_CLOSED;

      // @ Destroy itself
      unset(Connections::$Connections[$this->peer]);

      return true;
   }

   public function __destruct ()
   {
      foreach ($this->timers as $id) {
         Timer::del($id);
      }
   }
}
