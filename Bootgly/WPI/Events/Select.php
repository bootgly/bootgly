<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Events;


use function count;
use function hrtime;
use function is_int;
use function is_resource;
use function max;
use function microtime;
use function pcntl_signal_dispatch;
use function sleep;
use function stream_select;
use function usleep;
use Closure;
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
   /** @var array<int,array{deadline:float,Callback:Closure}> */
   private array $Timers = [];
   /** @var array<int,array{deadline:int,Callback:Closure}> */
   private array $MonotonicTimers = [];
   private int $timer = 0;
   // # Loop
   // ! Reusable reactor: assigned on every loop() entry/exit (never readonly)
   public float $started = 0.0;
   public float $finished = 0.0;


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
            $id = (int) $Socket;

            // System call select exceeded the maximum number of connections 1024.
            if (count($this->reads) >= 1000 && isset($this->reads[$id]) === false) {
               return false;
            }

            $this->reads[$id] = $Socket;

            $this->connecting[$id] = $payload;

            return true;
         // Package
         case self::EVENT_READ:
            $id = (int) $Socket;

            // System call select exceeded the maximum number of connections 1024.
            if (count($this->reads) >= 1000 && isset($this->reads[$id]) === false) {
               return false;
            }

            $this->reads[$id] = $Socket;

            $this->reading[$id] = $payload;

            return true;
         case self::EVENT_WRITE:
            $id = (int) $Socket;

            // System call select exceeded the maximum number of connections 1024.
            if (count($this->writes) >= 1000 && isset($this->writes[$id]) === false) {
               return false;
            }

            $this->writes[$id] = $Socket;

            $this->writing[$id] = $payload;

            return true;
         case self::EVENT_EXCEPT:
            $id = (int) $Socket;

            // System call select exceeded the maximum number of connections 1024.
            if (count($this->excepts) >= 1000 && isset($this->excepts[$id]) === false) {
               return false;
            }

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
            $this->release($this->awaitingReads, $this->awaitingReadDeadlines, $id);

            return true;
         // Package
         case self::EVENT_READ:
            $id = (int) $Socket;

            unset($this->reading[$id]);
            unset($this->reads[$id]);
            $this->release($this->awaitingReads, $this->awaitingReadDeadlines, $id);

            return true;
         case self::EVENT_WRITE:
            $id = (int) $Socket;

            unset($this->writing[$id]);
            unset($this->writes[$id]);
            $this->release($this->awaitingWrites, $this->awaitingWriteDeadlines, $id);

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
    * Register a one-shot callback. The clock domain is selected by type:
    * a float is a wall-clock `microtime(true)` deadline in seconds; an int
    * is a monotonic `hrtime(true)` deadline in nanoseconds.
    */
   public function defer (float|int $deadline, Closure $Callback): int
   {
      $ID = ++$this->timer;

      // ?: Monotonic deadlines are integer nanoseconds; wall-clock are float seconds.
      if (is_int($deadline)) {
         $this->MonotonicTimers[$ID] = [
            'deadline' => $deadline,
            'Callback' => $Callback
         ];

         return $ID;
      }

      $this->Timers[$ID] = [
         'deadline' => $deadline,
         'Callback' => $Callback
      ];

      return $ID;
   }

   /** Cancel a one-shot callback before it fires. */
   public function cancel (int $ID): bool
   {
      if (
         isset($this->Timers[$ID]) === false
         && isset($this->MonotonicTimers[$ID]) === false
      ) {
         return false;
      }
      unset($this->Timers[$ID]);
      unset($this->MonotonicTimers[$ID]);

      return true;
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
         $wait = $this->tick();
         // ? A timer callback dispatched by tick() may have stopped the loop
         if ($this->loop === false) { // @phpstan-ignore identical.alwaysFalse
            break;
         }

         // @ Resume tick-based Fibers (no I/O association)
         if ($this->Fibers) {
            foreach ($this->Fibers as $id => $Fiber) {
               if ($Fiber->isSuspended()) {
                  $value = $Fiber->resume();

                  // ? Pooled Fiber parked itself (job finished) — drop it
                  //   from the tick queue, never resume it without a job
                  if ($value === self::DETACH) {
                     unset($this->Fibers[$id]);

                     continue;
                  }

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

            $wait = $this->tick();
         }

         $read   = $this->reads;
         $write  = $this->writes;
         $except = $this->excepts;

         if ($read || $write || $except) {
            try {
               // @ Non-blocking poll if Fibers are suspended, otherwise block
               $timeout = $this->Fibers ? 0 : null;
               $microseconds = null;

               if ($timeout === null && $wait !== null) {
                  $remaining = max(0.0, $wait);
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
            if ($this->Fibers) {
               continue;
            }

            // @ Keep timer precision even when no sockets are registered.
            //   The historical one-second idle sleep remains the upper bound.
            if ($wait !== null && $wait < 1.0) {
               usleep((int) (max(0.0, $wait) * 1_000_000));
            }
            else {
               sleep(1);
            }

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

      // ? Pooled Fiber parked itself (job finished) — nothing to schedule
      if ($value === self::DETACH) {
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
      // ? Empty-set fast return — an ordinary HTTP worker has no one-shot
      //   timers or timed Fiber waits, yet tick() runs on every reactor
      //   iteration: skip the four clock reads and six map traversals.
      //   `null` means "no deadline" (indefinite select block) — never
      //   return 0.0 here, which would busy-poll.
      if (
         $this->Timers === [] && $this->MonotonicTimers === []
         && $this->awaitingReadDeadlines === [] && $this->awaitingWriteDeadlines === []
      ) {
         return null;
      }

      $now = microtime(true);
      $nowMonotonic = (int) hrtime(true);
      $wait = null;

      foreach ($this->Timers as $ID => $Timer) {
         if ($Timer['deadline'] > $now) {
            continue;
         }

         unset($this->Timers[$ID]);
         try {
            ($Timer['Callback'])();
         }
         catch (Throwable) {
            // One failed timeout callback must not tear down the event loop.
         }
      }

      foreach ($this->MonotonicTimers as $ID => $Timer) {
         if ($Timer['deadline'] > $nowMonotonic) {
            continue;
         }

         unset($this->MonotonicTimers[$ID]);
         try {
            ($Timer['Callback'])();
         }
         catch (Throwable) {
            // One failed timeout callback must not tear down the event loop.
         }
      }

      // @ Callbacks may cancel or register timers. Compute the next wait from
      //   the post-callback sets so a newly-nearest timer is never overslept.
      $now = microtime(true);
      $nowMonotonic = (int) hrtime(true);
      foreach ($this->Timers as $Timer) {
         $this->bound(max(0.0, $Timer['deadline'] - $now), $wait);
      }
      foreach ($this->MonotonicTimers as $Timer) {
         $this->bound(
            max(0.0, ($Timer['deadline'] - $nowMonotonic) / 1_000_000_000),
            $wait
         );
      }

      $this->expire($this->awaitingReads, $this->reads, $this->awaitingReadDeadlines, $now);
      $this->expire($this->awaitingWrites, $this->writes, $this->awaitingWriteDeadlines, $now);
      $this->limit($this->awaitingReadDeadlines, $now, $wait);
      $this->limit($this->awaitingWriteDeadlines, $now, $wait);

      return $wait;
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
    * Move Fibers awaiting a removed socket back to tick scheduling.
    *
    * @param array<int,array<int,Fiber<mixed,mixed,mixed,mixed>>> $Fibers
    * @param array<int,float> $deadlines
    */
   private function release (array &$Fibers, array &$deadlines, int $id): void
   {
      $Queued = $Fibers[$id] ?? [];

      unset($Fibers[$id]);
      unset($deadlines[$id]);

      foreach ($Queued as $Fiber) {
         if ($Fiber->isSuspended()) {
            $this->Fibers[] = $Fiber;
         }
      }
   }

   /**
    * Limit stream_select by the nearest timed I/O deadline.
    *
    * @param array<int,float> $deadlines
    */
   private function limit (array $deadlines, float $now, null|float &$next): void
   {
      foreach ($deadlines as $deadline) {
         if ($deadline <= 0.0) {
            continue;
         }

         $this->bound(max(0.0, $deadline - $now), $next);
      }
   }

   /**
    * Keep the nearest relative wait in seconds.
    *
    * @param-out float $next
    */
   private function bound (float $wait, null|float &$next): void
   {
      if ($next === null || $wait < $next) {
         $next = $wait;
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

      // ? Pooled Fiber parked itself (job finished) — drop, do not requeue
      if ($value === self::DETACH) {
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

      // # Events (payload maps — a persistent reactor must not retain
      //   stale Connection references between drains)
      $this->connecting = [];
      $this->reading = [];
      $this->writing = [];
      $this->excepting = [];

      // # Async
      $this->awaitingReads = [];
      $this->awaitingWrites = [];
      $this->awaitingReadDeadlines = [];
      $this->awaitingWriteDeadlines = [];
      $this->Fibers = [];
      $this->Timers = [];
      $this->MonotonicTimers = [];

      // # Loop
      $this->loop = false;
   }
}
