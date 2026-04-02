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


use function count;
use function is_resource;
use function microtime;
use function pcntl_signal_dispatch;
use function sleep;
use function stream_select;
use Fiber;
use Throwable;

use Bootgly\ACI\Events\Loops;
use Bootgly\ACI\Events\Scheduler;
use Bootgly\WPI\Connections;
use Bootgly\WPI\Events;


class Select implements Events, Loops, Scheduler
{
   public Connections $Connections;

   // * Config
   public bool $loop = true;

   // * Data
   // # Sockets
   /** @var array<int,resource> */
   protected array $reads = [];
   /** @var array<int,resource> */
   protected array $writes = [];
   /** @var array<int,resource> */
   protected array $excepts = [];

   // * Metadata
   // # Events
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
   // # Async
   // Tick-based (resumed every iteration)
   /** @var array<int,Fiber<mixed,mixed,mixed,mixed>> */
   private array $Fibers = [];
   // I/O-bound (resumed when stream_select signals readiness)
   /** @var array<int,Fiber<mixed,mixed,mixed,mixed>> */
   private array $awaiting = [];
   // # Loop
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
            if (count($this->reads) >= 1000) {
               return false;
            }

            $id = (int) $Socket;

            $this->reads[$id] = $Socket;

            $this->connecting[$id] = $payload;

            return true;
         // Package
         case self::EVENT_READ:
            // System call select exceeded the maximum number of connections 1024.
            if (count($this->reads) >= 1000) {
               return false;
            }

            $id = (int) $Socket;

            $this->reads[$id] = $Socket;

            $this->reading[$id] = $payload;

            return true;
         case self::EVENT_WRITE:
            // System call select exceeded the maximum number of connections 1024.
            if (count($this->writes) >= 1000) {
               return false;
            }

            $id = (int) $Socket;

            $this->writes[$id] = $Socket;

            $this->writing[$id] = $payload;

            return true;
         case self::EVENT_EXCEPT:
            // System call select exceeded the maximum number of connections 1024.
            if (count($this->excepts) >= 1000) {
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
    * Start the event loop (Fiber-scheduled).
    *
    * @return void
    */
   public function loop (): void
   {
      $this->started = microtime(true);

      $Connections = $this->Connections;

      while (true) {
         pcntl_signal_dispatch();

         // @ Resume tick-based Fibers (no I/O association)
         if ($this->Fibers) {
            foreach ($this->Fibers as $id => $Fiber) {
               if ($Fiber->isSuspended()) {
                  $value = $Fiber->resume();

                  // @ Convert to I/O-awaiting if Fiber suspended with a socket
                  if ( !$Fiber->isTerminated() && is_resource($value)) {
                     unset($this->Fibers[$id]);

                     $socketId = (int) $value;
                     $this->awaiting[$socketId] = $Fiber;
                     $this->reads[$socketId] = $value;

                     continue;
                  }
               }

               if ($Fiber->isTerminated()) {
                  unset($this->Fibers[$id]);
               }
            }
         }

         $read   = $this->reads;
         $write  = $this->writes;
         $except = $this->excepts;

         if ($read || $write || $except) {
            try {
               // @ Non-blocking poll if Fibers are suspended, otherwise block
               $timeout = $this->Fibers ? 0 : null;
               // Waiting for read / write / excepts events.
               $streams = @stream_select($read, $write, $except, $timeout);
            }
            catch (Throwable) {
               $streams = false;
            }
         }
         else {
            // @ Sleep for 1 second and continue (Used to pause the Server)
            sleep(1);

            if ($this->loop === false) {
               break;
            }

            continue;
         }

         if ($streams === false || $streams === 0) {
            continue;
         }

         // @ Dispatch (direct call — Fibers are created by handlers when needed)
         if ($read) {
            foreach ($read as $Socket) {
               $id = (int) $Socket;

               // @ Resume I/O-awaiting Fiber (stream is now readable)
               if ( isSet($this->awaiting[$id]) ) {
                  $Fiber = $this->awaiting[$id];

                  unset($this->awaiting[$id]);
                  unset($this->reads[$id]);

                  if ($Fiber->isSuspended()) {
                     $value = $Fiber->resume();

                     // @ Re-schedule if Fiber suspended again
                     if ( !$Fiber->isTerminated()) {
                        if (is_resource($value)) {
                           $newId = (int) $value;

                           $this->awaiting[$newId] = $Fiber;

                           $this->reads[$newId] = $value;
                        }
                        else {
                           $this->Fibers[] = $Fiber;
                        }
                     }
                  }

                  continue;
               }

               // @ Select action
               if ( isSet($this->connecting[$id]) ) {
                  $Connections->connect();
               }
               else if ( isSet($this->reading[$id]) ) {
                  /** @var Connections\Packages $Package */
                  $Package = &$this->reading[$id];
                  $Package->reading($Socket);
               }
            }
         }

         if ($write) {
            foreach ($write as $Socket) {
               $id = (int) $Socket;

               if ( isSet($this->writing[$id]) ) {
                  /** @var Connections\Packages $Package */
                  $Package = &$this->writing[$id];
                  $Package->writing($Socket);
               }
            }
         }

         // TODO add timer ticks?
         // if ($except) {}
      }

   $this->finished = microtime(true);
   }

   /**
    * Schedule a suspended Fiber for resumption in the event loop.
    *
    * When $value is a stream resource, the Fiber becomes I/O-bound:
    * it is registered in stream_select() and only resumed when readable.
    * When $value is null, the Fiber is tick-based: resumed every iteration.
    *
    * @param Fiber<mixed, mixed, mixed, mixed> $Fiber
    * @param mixed $value The suspended value from Fiber::start() or resume().
    *
    * @return bool
    */
   public function schedule (Fiber $Fiber, mixed $value = null): bool
   {
      // ?
      if ($Fiber->isTerminated()) {
         return false;
      }

      // @ I/O-bound: register socket in stream_select + map to Fiber
      if (is_resource($value)) {
         $id = (int) $value;

         $this->awaiting[$id] = $Fiber;
         $this->reads[$id] = $value;

         return true;
      }

      // @ Tick-based: resume every iteration
      $this->Fibers[] = $Fiber;

      // :
      return true;
   }

   /**
    * Stop the event loop.
    *
    * @return void
    */
   public function destroy (): void
   {
      $this->reads = [];
      $this->writes = [];
      $this->excepts = [];

      // # Async
      $this->awaiting = [];
      $this->Fibers = [];

      // # Loop
      $this->loop = false;
   }
}
