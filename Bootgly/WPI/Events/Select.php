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
use Bootgly\ACI\Events\Readiness;
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
   /** @var array<int,array<int,Fiber<mixed,mixed,mixed,mixed>>> */
   private array $awaitingReads = [];
   /** @var array<int,array<int,Fiber<mixed,mixed,mixed,mixed>>> */
   private array $awaitingWrites = [];
   /** @var array<int,float> */
   private array $awaitingReadDeadlines = [];
   /** @var array<int,float> */
   private array $awaitingWriteDeadlines = [];
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
         if (!$this->loop) {
            break;
         }

         pcntl_signal_dispatch();
         $deadline = $this->tick();

         // @ Resume tick-based Fibers (no I/O association)
         if ($this->Fibers) {
            foreach ($this->Fibers as $id => $Fiber) {
               if ($Fiber->isSuspended()) {
                  $value = $Fiber->resume();

                  // @ Convert to I/O-awaiting if Fiber suspended with readiness
                  if ( !$Fiber->isTerminated() && $this->queue($Fiber, $value)) {
                     unset($this->Fibers[$id]);

                     continue;
                  }
               }

               if ($Fiber->isTerminated()) {
                  unset($this->Fibers[$id]);
               }
            }

            $deadline = $this->tick();
         }

         $read   = $this->reads;
         $write  = $this->writes;
         $except = $this->excepts;

         if ($read || $write || $except) {
            try {
               // @ Non-blocking poll if Fibers are suspended, otherwise block
               $timeout = $this->Fibers ? 0 : null;
               $microseconds = null;

               if ($timeout === null && $deadline !== null) {
                  $remaining = $deadline - microtime(true);

                  if ($remaining < 0) {
                     $remaining = 0.0;
                  }

                  $timeout = (int) $remaining;
                  $microseconds = (int) (($remaining - $timeout) * 1_000_000);
               }

               // Waiting for read / write / excepts events.
               $streams = $microseconds === null
                  ? @stream_select($read, $write, $except, $timeout)
                  : @stream_select($read, $write, $except, $timeout, $microseconds);
            }
            catch (Throwable) {
               $streams = false;
            }
         }
         else {
            // @ Sleep for 1 second and continue (Used to pause the Server)
            sleep(1);

            if ($this->loop === false) { // @phpstan-ignore identical.alwaysFalse
               break;
            }

            continue;
         }

         if ($streams === false || $streams === 0) {
            $this->tick();

            continue;
         }

         // @ Dispatch (direct call — Fibers are created by handlers when needed)
         if ($read) {
            foreach ($read as $Socket) {
               $id = (int) $Socket;

               // @ Resume I/O-awaiting Fiber (stream is now readable)
               if ( isSet($this->awaitingReads[$id]) ) {
                  $Fibers = $this->awaitingReads[$id];

                  unset($this->awaitingReads[$id]);
                  unset($this->awaitingReadDeadlines[$id]);
                  unset($this->reads[$id]);

                  foreach ($Fibers as $Fiber) {
                     $this->resume($Fiber);
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

               // @ Resume I/O-awaiting Fiber (stream is now writable)
               if ( isSet($this->awaitingWrites[$id]) ) {
                  $Fibers = $this->awaitingWrites[$id];

                  unset($this->awaitingWrites[$id]);
                  unset($this->awaitingWriteDeadlines[$id]);
                  unset($this->writes[$id]);

                  foreach ($Fibers as $Fiber) {
                     $this->resume($Fiber);
                  }

                  continue;
               }

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
    * When $value is a stream resource, the Fiber becomes read I/O-bound:
    * it is registered in stream_select() and only resumed when readable.
    * When $value is a Readiness object, the Fiber becomes read/write I/O-bound
    * according to Readiness::$flag.
    * When $value is null, the Fiber is tick-based: resumed every iteration.
    *
    * @param Fiber<mixed, mixed, mixed, mixed> $Fiber
    * @param mixed $value The suspended value from Fiber::start() or resume().
    *
    * @return bool
    */
   public function schedule (Fiber $Fiber, mixed $value = null, int $flag = self::SCHEDULE_READ): bool
   {
      // ?
      if ($Fiber->isTerminated()) {
         return false;
      }

      // @ I/O-bound: register socket in stream_select + map to Fiber
      if ($this->queue($Fiber, $value, $flag)) {
         return true;
      }

      // @ Tick-based: resume every iteration
      $this->Fibers[] = $Fiber;

      // :
      return true;
   }

   /**
    * Queue a Fiber by explicit stream readiness.
    *
    * @param Fiber<mixed, mixed, mixed, mixed> $Fiber
    * @param mixed $value
    */
   private function queue (Fiber $Fiber, mixed $value = null, int $flag = self::SCHEDULE_READ): bool
   {
      $deadline = 0.0;

      if ($value instanceof Readiness) {
         $flag = $value->flag;
         $deadline = $value->deadline;
         $value = $value->socket;
      }

      if (is_resource($value) === false) {
         return false;
      }

      $id = (int) $value;

      if ($flag === self::SCHEDULE_WRITE) {
         foreach ($this->awaitingWrites[$id] ?? [] as $Queued) {
            if ($Queued === $Fiber) {
               return true;
            }
         }

         $this->awaitingWrites[$id][] = $Fiber;
         $this->track($this->awaitingWriteDeadlines, $id, $deadline);
         $this->writes[$id] = $value;

         return true;
      }

      foreach ($this->awaitingReads[$id] ?? [] as $Queued) {
         if ($Queued === $Fiber) {
            return true;
         }
      }

      $this->awaitingReads[$id][] = $Fiber;
      $this->track($this->awaitingReadDeadlines, $id, $deadline);
      $this->reads[$id] = $value;

      return true;
   }

   /**
    * Register the nearest deadline for a socket wait list.
    *
    * @param array<int,float> $deadlines
    */
   private function track (array &$deadlines, int $id, float $deadline): void
   {
      if (isset($deadlines[$id]) === false) {
         $deadlines[$id] = $deadline;

         return;
      }

      if ($deadline > 0.0 && ($deadlines[$id] <= 0.0 || $deadline < $deadlines[$id])) {
         $deadlines[$id] = $deadline;
      }
   }

   /**
    * Tick timed I/O Fibers and return the next deadline.
    */
   private function tick (): null|float
   {
      $now = microtime(true);
      $deadline = null;

      $this->expire($this->awaitingReads, $this->reads, $this->awaitingReadDeadlines, $now);
      $this->expire($this->awaitingWrites, $this->writes, $this->awaitingWriteDeadlines, $now);
      $this->limit($this->awaitingReadDeadlines, $deadline);
      $this->limit($this->awaitingWriteDeadlines, $deadline);

      return $deadline;
   }

   /**
    * Expire timed I/O Fibers.
    *
   * @param array<int,array<int,Fiber<mixed,mixed,mixed,mixed>>> $Fibers
    * @param array<int,resource> $sockets
    * @param array<int,float> $deadlines
    */
   private function expire (array &$Fibers, array &$sockets, array &$deadlines, float $now): void
   {
      foreach ($deadlines as $id => $deadline) {
         if ($deadline <= 0.0) {
            continue;
         }

         if ($deadline > $now) {
            continue;
         }

         $Queued = $Fibers[$id] ?? [];

         unset($Fibers[$id]);
         unset($sockets[$id]);
         unset($deadlines[$id]);

         foreach ($Queued as $Fiber) {
            $this->resume($Fiber);
         }
      }
   }

   /**
    * Limit stream_select by the nearest timed I/O deadline.
    *
    * @param array<int,float> $deadlines
    */
   private function limit (array $deadlines, null|float &$next): void
   {
      foreach ($deadlines as $deadline) {
         if ($deadline <= 0.0) {
            continue;
         }

         if ($next === null || $deadline < $next) {
            $next = $deadline;
         }
      }
   }

   /**
    * Resume one suspended Fiber and requeue its next wait target.
    *
    * @param Fiber<mixed,mixed,mixed,mixed> $Fiber
    */
   private function resume (Fiber $Fiber): void
   {
      if ($Fiber->isSuspended() === false) {
         return;
      }

      $value = $Fiber->resume();

      if ($Fiber->isTerminated()) {
         return;
      }

      if ($this->queue($Fiber, $value) === false) {
         $this->Fibers[] = $Fiber;
      }
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
      $this->awaitingReads = [];
      $this->awaitingWrites = [];
      $this->awaitingReadDeadlines = [];
      $this->awaitingWriteDeadlines = [];
      $this->Fibers = [];

      // # Loop
      $this->loop = false;
   }
}
