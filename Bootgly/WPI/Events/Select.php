<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Events;


use Bootgly\ACI\Events\Loops;

use Bootgly\WPI\Connections;
use Bootgly\WPI\Events;


class Select implements Events, Loops
{
   public Connections $Connections;

   // * Config
   public bool $loop = true;

   // * Data
   // @ Sockets
   /** @var array<int,resource> */
   protected array $reads = [];
   /** @var array<int,resource> */
   protected array $writes = [];
   /** @var array<int,resource> */
   protected array $excepts = [];

   // * Metadata
   // @ Events
   // Client/Server
   /** @var array<int,mixed> */
   private array $connecting = [];
   // Package
   /** @var array<int,mixed> */
   private array $reading = [];
   /** @var array<int,mixed> */
   private array $writing = [];
   /** @var array<int,mixed> */
   private array $excepting = [];
   // @ Loop
   public readonly float $started;
   public readonly float $finished;


   public function __construct (Connections &$Connections)
   {
      $this->Connections = $Connections;
   }

   /**
    * Add a socket to the event loop.
    * 
    * @param resource $Socket
    * @param int $flag
    * @param mixed $payload
    *
    * @return bool
    */
   public function add ($Socket, int $flag, mixed $payload): bool
   {
      switch ($flag) {
         // Client/Server
         case self::EVENT_CONNECT:
            // System call select exceeded the maximum number of connections 1024.
            if (\count($this->reads) >= 1000) {
               return false;
            }

            $id = (int) $Socket;

            $this->reads[$id] = $Socket;

            $this->connecting[$id] = $payload;

            return true;
         // Package
         case self::EVENT_READ:
            // System call select exceeded the maximum number of connections 1024.
            if (\count($this->reads) >= 1000) {
               return false;
            }

            $id = (int) $Socket;

            $this->reads[$id] = $Socket;

            $this->reading[$id] = $payload;

            return true;
         case self::EVENT_WRITE:
            // System call select exceeded the maximum number of connections 1024.
            if (\count($this->writes) >= 1000) {
               return false;
            }

            $id = (int) $Socket;

            $this->writes[$id] = $Socket;

            $this->writing[$id] = $payload;

            return true;
         case self::EVENT_EXCEPT:
            // System call select exceeded the maximum number of connections 1024.
            if (\count($this->excepts) >= 1000) {
               return false;
            }

            $id = (int) $Socket;

            $this->excepts[$id] = $Socket;

            $this->excepting[$id] = $payload;

            return true;
      }

      return false;
   }
   /**
    * Remove a socket from the event loop.
    * 
    * @param resource $Socket
    * @param int $flag
    *
    * @return bool
    */
   public function del ($Socket, int $flag): bool
   {
      switch ($flag) {
         // Client/Server
         case self::EVENT_CONNECT:
            $id = (int) $Socket;

            unset($this->connecting[$id]);
            unset($this->reads[$id]);

            return true;
         // Package
         case self::EVENT_READ:
            $id = (int) $Socket;

            unset($this->reading[$id]);
            unset($this->reads[$id]);

            return true;
         case self::EVENT_WRITE:
            $id = (int) $Socket;

            unset($this->writing[$id]);
            unset($this->writes[$id]);

            return true;
         case self::EVENT_EXCEPT:
            $id = (int) $Socket;

            unset($this->excepting[$id]);
            unset($this->excepts[$id]);

            return true;
      }

      return false;
   }

   /**
    * Start the event loop.
    *
    * @return void
    */
   public function loop (): void
   {
      $this->started = \microtime(true);

      $Connections = $this->Connections;

      while (true) {
         \pcntl_signal_dispatch();

         $read   = $this->reads;
         $write  = $this->writes;
         $except = $this->excepts;

         if ($read || $write || $except) {
            try {
               // Waiting $this->timeout for read / write / excepts events.
               $streams = @\stream_select($read, $write, $except, null);
            }
            catch (\Throwable) {
               $streams = false;
            }
         }
         else {
            // @ Sleep for 1 second and continue (Used to pause the Server)
            \sleep(1);

            if ($this->loop === false) {
               break;
            }

            continue;
         }

         if ($streams === false || $streams === 0) {
            continue;
         }

         // @ Call
         if ($read) {
            foreach ($read as $Socket) {
               $id = (int) $Socket;

               // @ Select action
               if ( isSet($this->connecting[$id]) ) {
                  $Connections->connect();
               }
               else if ( isSet($this->reading[$id]) ) {
                  $Package = &$this->reading[$id];
                  $Package->reading($Socket);
               }
            }
         }

         if ($write) {
            foreach ($write as $Socket) {
               $id = (int) $Socket;

               if ( isSet($this->writing[$id]) ) {
                  $Package = &$this->writing[$id];
                  $Package->writing($Socket);
               }
            }
         }

         // TODO add timer ticks?
         // if ($except) {}
      }

      $this->finished = \microtime(true);
   }

   /**
    * Stop the event loop.
    *
    * @return void
    */
   public function destroy (): void
   {
      $this->loop = false;
   }
}
