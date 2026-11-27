<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Interfaces\UDP_Client_CLI\Connections;


use function fclose;
use function stream_socket_get_name;
use function time;
use Throwable;

use Bootgly\ACI\Events\Loops;
use Bootgly\ACI\Events\Scheduler;
use Bootgly\ACI\Events\Timer;
use Bootgly\WPI\Connections\Peer;
use Bootgly\WPI\Events;
use Bootgly\WPI\Interfaces\UDP_Client_CLI as Client;
use Bootgly\WPI\Interfaces\UDP_Client_CLI\Connections;
use Bootgly\WPI\Interfaces\UDP_Client_CLI\Packages;


class Connection extends Packages
{
   // * Config
   /** @var resource */
   public $Socket;
   /**
    * The embedded client this flow belongs to (`MODE_EMBEDDED`); `null` for a
    * legacy flow, which uses the process-wide statics.
    */
   public private(set) null|Client $Client = null;


   /** @var array<false|int> */
   public array $timers;
   public int $expiration;

   // * Data
   // # Remote
   public string $address;
   public int $port;

   // * Metadata
   /**
    * Reactor this flow lives in (embedded), stamped at construction: the
    * client may adopt another one later, but THIS socket stays where it was.
    */
   public private(set) null|(Events & Loops & Scheduler) $Reactor = null;
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
    * @param null|Client $Client The embedded client that owns this flow
    *    (`MODE_EMBEDDED`); omitted, the flow uses the legacy statics.
    */
   public function __construct (&$Socket, null|Client $Client = null)
   {
      $this->Socket = $Socket;
      $this->Client = $Client;
      // ! Stamp the reactor before anything can close this flow
      $this->Reactor = $Client?->Reactor;


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
      if ($Client !== null) {
         $Hook = $Client->Callbacks['clientConnect'] ?? null;
         if ($Hook !== null) {
            $Hook($Socket, $this);
         }

         return;
      }
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

      $Client = $this->Client;

      // @ Leave the reactor this flow lives in
      if ($Client !== null) {
         $Reactor = $this->Reactor;
         // ! Even a socket closed elsewhere: its id keeps the registrations
         if ($Reactor !== null) {
            try {
               $Reactor->del($this->Socket, $Reactor::EVENT_WRITE);
               $Reactor->del($this->Socket, $Reactor::EVENT_READ);
            }
            catch (Throwable) {
               // ...
            }
         }
      }
      else {
         Client::$Event->del($this->Socket, Client::$Event::EVENT_WRITE);
         Client::$Event->del($this->Socket, Client::$Event::EVENT_READ);
      }

      try {
         @fclose($this->Socket);
      }
      catch (Throwable) {
         // ...
      }

      $this->status = self::STATUS_CLOSED;

      // ? Embedded: registry, then the instance hook
      if ($Client !== null) {
         // @ Destroy itself — before the hook, so a hook that throws never leaves a closed flow registered
         $Client->Connections->release($this);

         $Hook = $Client->Callbacks['clientDisconnect'] ?? null;
         if ($Hook !== null) {
            $Hook($this);
         }

         return true;
      }

      if (Client::$onClientDisconnect) {
         (Client::$onClientDisconnect)($this);
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
