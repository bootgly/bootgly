<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Events;


use function array_key_last;
use function count;
use function get_debug_type;
use function hrtime;
use function is_int;
use function is_resource;
use function max;
use function microtime;
use function min;
use function pcntl_signal_dispatch;
use function sleep;
use function spl_object_id;
use function stream_select;
use function usleep;
use Closure;
use Fiber;
use LogicException;
use RuntimeException;
use Throwable;
use WeakMap;
use WeakReference;

use Bootgly\ACI\Events\Cancelling;
use Bootgly\ACI\Events\Loops;
use Bootgly\ACI\Events\Readiness;
use Bootgly\ACI\Events\Scheduler;
use Bootgly\WPI\Connections;
use Bootgly\WPI\Events;


class Select implements Events, Loops, Scheduler, Cancelling
{
   public Connections $Connections;

   // * Config
   public bool $loop = true;
   /** Reentrancy tripwire: a nested loop() shares one shutdown key and would
    * silently kill the outer loop; stays latched if loop() exits by exception. */
   private bool $entered = false;

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
   /** @var array<int,array{Enter:Closure,Leave:Closure,Token:null|Cancellation}> */
   private array $Bindings = [];
   // # Where each Fiber entered the queues below, so evicting one generation
   //   costs its own entries instead of a sweep of every parked waiter.
   //   Deliberately a SUPERSET: an entry consumed by readiness dispatch,
   //   expiry or release leaves its location behind, which costs one lookup
   //   and is dropped with the rest. It is never a subset — `queue()` and
   //   `park()` are the only writers of the queues they describe. Weak keys
   //   so a collected Fiber needs no bookkeeping and an id cannot be reused.
   /** @var null|WeakMap<Fiber<mixed,mixed,mixed,mixed>,array<int,true>> */
   private null|WeakMap $Waits = null;
   /** @var null|WeakMap<Fiber<mixed,mixed,mixed,mixed>,array<int,true>> */
   private null|WeakMap $Ticks = null;
   // I/O-bound (resumed when stream_select signals readiness)
   /** @var array<int,array<int,Fiber<mixed,mixed,mixed,mixed>>> */
   private array $awaitingReads = [];
   /** @var array<int,array<int,Fiber<mixed,mixed,mixed,mixed>>> */
   private array $awaitingWrites = [];
   /** @var array<int,float> */
   private array $awaitingReadDeadlines = [];
   /** @var array<int,float> */
   private array $awaitingWriteDeadlines = [];
   /**
    * The most scheduled waiters this reactor has held at once — the tick
    * queue plus one entry per awaited descriptor.
    *
    * Deferred work competes with client connections for the same selector
    * budget, so this is the share of the 1000-descriptor cap the application
    * takes, and the population every reactor-wide operation walks. An
    * instantaneous count says nothing (a sample almost always lands between
    * waits); only the peak states which regime a workload actually reached.
    */
   public private(set) int $parked = 0;
   /** @var array<int,array{deadline:float,Callback:Closure}> */
   private array $Timers = [];
   /** @var array<int,array{deadline:int,Callback:Closure}> */
   private array $MonotonicTimers = [];
   private int $timer = 0;
   // # Backend
   // @ Consecutive false selector returns. A timeout is not a failure.
   private int $failures = 0;
   // # Loop
   // ! Reusable reactor: assigned on every loop() entry/exit (never readonly)
   public float $started = 0.0;
   public float $finished = 0.0;
   /**
    * One monotonic stamp per dispatching select() wakeup — every socket
    * dispatched in that wakeup became ready before this instant, so hot read
    * callbacks may reuse it instead of paying one clock syscall per socket.
    */
   public private(set) int $wakeNS = 0;


   public function __construct (Connections &$Connections)
   {
      $this->Connections = $Connections;
   }

   /**
    * Check that one new resource is representable by this select backend.
    *
    * PHP resource IDs are not OS descriptor numbers, and an array-count cap
    * cannot detect an FD at or above the build's FD_SETSIZE. Probe the exact
    * resource once before persistent admission. Resources already present in
    * any selector set were validated on their first admission.
    *
    * @param resource $Socket
    */
   protected function check ($Socket, int $flag): bool
   {
      $id = (int) $Socket;
      if (
         isset($this->reads[$id])
         || isset($this->writes[$id])
         || isset($this->excepts[$id])
      ) {
         return true;
      }

      for ($attempt = 0; $attempt < 2; $attempt++) {
         $read = [];
         $write = [];
         $except = [];
         match ($flag) {
            self::EVENT_CONNECT, self::EVENT_READ => $read[] = $Socket,
            self::EVENT_WRITE => $write[] = $Socket,
            self::EVENT_EXCEPT => $except[] = $Socket,
            default => null,
         };
         if ($read === [] && $write === [] && $except === []) {
            return false;
         }

         try {
            $selected = @stream_select($read, $write, $except, 0, 0);
         }
         catch (Throwable) {
            $selected = false;
         }
         if ($selected !== false) {
            return true;
         }

         // ? A signal can race even a zero-time probe. Dispatch it once and
         //   retry with fresh arrays before rejecting a valid descriptor.
         pcntl_signal_dispatch();
      }

      return false;
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
            if (
               isset($this->reads[$id]) === false
               && (
                  count($this->reads) >= 1000
                  || $this->check($Socket, $flag) === false
               )
            ) {
               return false;
            }

            $this->reads[$id] = $Socket;

            $this->connecting[$id] = $payload;

            return true;
         // Package
         case self::EVENT_READ:
            $id = (int) $Socket;

            // System call select exceeded the maximum number of connections 1024.
            if (
               isset($this->reads[$id]) === false
               && (
                  count($this->reads) >= 1000
                  || $this->check($Socket, $flag) === false
               )
            ) {
               return false;
            }

            $this->reads[$id] = $Socket;

            $this->reading[$id] = $payload;

            return true;
         case self::EVENT_WRITE:
            $id = (int) $Socket;

            // System call select exceeded the maximum number of connections 1024.
            if (
               isset($this->writes[$id]) === false
               && (
                  count($this->writes) >= 1000
                  || $this->check($Socket, $flag) === false
               )
            ) {
               return false;
            }

            $this->writes[$id] = $Socket;

            $this->writing[$id] = $payload;

            return true;
         case self::EVENT_EXCEPT:
            $id = (int) $Socket;

            // System call select exceeded the maximum number of connections 1024.
            if (
               isset($this->excepts[$id]) === false
               && (
                  count($this->excepts) >= 1000
                  || $this->check($Socket, $flag) === false
               )
            ) {
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
      // ? A nested loop() is never legal — fail loud instead of wedging the host
      if ($this->entered) {
         throw new LogicException('The event loop is not reentrant.');
      }
      $this->entered = true;

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
            // ! Capture every queued generation before the first callback.
            //   One Fiber may terminalize another later entry in this batch.
            $Generations = $this->capture($this->Fibers);
            foreach ($Generations as $id => $Generation) {
               $Fiber = $Generation['Fiber'];
               $Token = $Generation['Token'];

               // ! The binding owns one exact pooled-Fiber generation. A
               //   terminal generation must never execute or be requeued.
               if ($Token?->check() === true) {
                  $this->evict($Fiber, $Token);

                  continue;
               }

               if ($Fiber->isSuspended()) {
                  $value = $this->Bindings === []
                     ? $Fiber->resume()
                     : $this->advance($Fiber);

                  // ? Resumed user code may have settled the captured token.
                  if ($Token?->check() === true) { // @phpstan-ignore identical.alwaysFalse
                     $this->evict($Fiber, $Token);

                     continue;
                  }

                  // ? Pooled Fiber parked itself (job finished) — drop it
                  //   from the tick queue, never resume it without a job
                  if ($value === self::DETACH) {
                     unset($this->Fibers[$id]);
                     $this->evict($Fiber, $Token);

                     continue;
                  }

                  // @ Convert to I/O-awaiting if Fiber suspended with readiness
                  if (!$Fiber->isTerminated()) {
                     $queued = $this->queue($Fiber, $value);
                     // ? Selector signal callbacks may settle the token.
                     if ($Token?->check() === true) { // @phpstan-ignore identical.alwaysFalse
                        $this->evict($Fiber, $Token);

                        continue;
                     }
                     if ($queued === true) {
                        unset($this->Fibers[$id]);

                        continue;
                     }
                     if ($queued === false) {
                        unset($this->Fibers[$id]);
                        $this->reject($Fiber);

                        continue;
                     }
                  }
               }

               if ($Fiber->isTerminated()) {
                  unset($this->Fibers[$id]);
                  $this->evict($Fiber, $Token);
               }
            }

            $wait = $this->tick();
         }

         // ! `stream_select()` takes each set by reference and rewrites it with
         //   the ready subset, so every non-null set is separated and rebuilt
         //   on every wakeup. An HTTP worker registers writes/excepts only
         //   under backpressure: passing null for an empty set skips its copy
         //   and its fd_set marshalling entirely (the parameters are `?array`).
         $read = $this->reads;
         $write = $this->writes ?: null;
         $except = $this->excepts ?: null;

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

               // Waits for read / write / excepts events.
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

         if ($streams === false) {
            // ? Every worker receives a one-second SIGALRM. PHP reports an
            //   interrupted blocking select and an invalid descriptor set as
            //   the same `false`, so dispatch the signal and immediately
            //   probe the current persistent sets without blocking.
            pcntl_signal_dispatch();
            // ! Same null-for-empty shape as the main call above, so both
            //   paths hand the dispatch below identically-typed sets.
            $read = $this->reads;
            $write = $this->writes ?: null;
            $except = $this->excepts ?: null;
            if ($read || $write || $except) {
               try {
                  $streams = @stream_select($read, $write, $except, 0, 0);
               }
               catch (Throwable) {
                  $streams = false;
               }
            }
            else {
               $streams = 0;
            }

            if ($streams === false) {
               $this->failures++;
               $this->tick();

               // ! The immediate retry proved that this was not merely the
               //   expected alarm interruption. Back off before retrying and
               //   recycle a persistently invalid worker instead of hot-spin.
               usleep(min(100_000, 1_000 << min(6, $this->failures - 1)));
               if ($this->failures >= 3) {
                  throw new RuntimeException(
                     'stream_select failed on the admitted event set.'
                  );
               }

               continue;
            }
         }
         $this->failures = 0;

         if ($streams === 0) {
            $this->tick();

            continue;
         }

         // ! One wakeup stamp for the whole dispatch below (see $wakeNS).
         $this->wakeNS = (int) hrtime(true);

         // @ Dispatch (direct call — Fibers are created by handlers when needed)
         if ($read) {
            foreach ($read as $Socket) {
               $id = (int) $Socket;

               // @ Resume I/O-awaiting Fiber (stream is now readable)
               if ( isSet($this->awaitingReads[$id]) ) {
                  $Fibers = $this->awaitingReads[$id];

                  unset($this->awaitingReads[$id]);
                  unset($this->awaitingReadDeadlines[$id]);
                  if (
                     isSet($this->reading[$id]) === false
                     && isSet($this->connecting[$id]) === false
                  ) {
                     unset($this->reads[$id]);
                  }

                  foreach ($this->capture($Fibers) as $Generation) {
                     $this->resume(
                        $Generation['Fiber'],
                        $Generation['Token'],
                     );
                  }

                  // ? The same descriptor may also belong to a persistent
                  //   listener/package. Resume waiters and dispatch that base
                  //   owner during this already-observed readiness wakeup.
                  if (
                     isSet($this->reading[$id]) === false
                     && isSet($this->connecting[$id]) === false
                  ) {
                     continue;
                  }
               }

               // @ Select action
               if ( isSet($this->connecting[$id]) ) {
                  $Connections->connect();

                  continue;
               }

               // ! One lookup, by value: `??` treats a null payload as absent
               //   exactly like the isSet() it replaces, and the local is only
               //   ever a call receiver — a `&` binding would convert the slot
               //   into a permanent reference for the connection's whole life.
               /** @var null|Connections\Packages $Package */
               $Package = $this->reading[$id] ?? null;
               if ($Package !== null) {
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
                  if (isSet($this->writing[$id]) === false) {
                     unset($this->writes[$id]);
                  }

                  foreach ($this->capture($Fibers) as $Generation) {
                     $this->resume(
                        $Generation['Fiber'],
                        $Generation['Token'],
                     );
                  }

                  if (isSet($this->writing[$id]) === false) {
                     continue;
                  }
               }

               // ! Same single-lookup, by-value shape as the read dispatch.
               /** @var null|Connections\Packages $Package */
               $Package = $this->writing[$id] ?? null;
               if ($Package !== null) {
                  $Package->writing($Socket);
               }
            }
         }

         // TODO add timer ticks?
         // if ($except) {}
      }

      $this->entered = false;
      $this->finished = microtime(true);
   }

   /**
    * Schedule a suspended Fiber for resumption in the event loop.
    *
    * When $value is a stream resource, the Fiber becomes read I/O-bound:
    * it is registered in stream_select() and only resumed when readable.
    * When $value is a Readiness object, the Fiber becomes read/write I/O-bound
    * according to Readiness::$flag.
    * Other suspended values are tick-based: resumed every iteration.
    *
    * @param Fiber<mixed, mixed, mixed, mixed> $Fiber
    * @param mixed $value The suspended value from Fiber::start() or resume().
    *
    * @return bool False when an explicit I/O wait cannot be admitted.
    *    Rejection is delivered into the suspended Fiber so its normal
    *    exception/finally path can terminate without poisoning the reactor.
    */
   public function schedule (Fiber $Fiber, mixed $value = null, int $flag = self::SCHEDULE_READ): bool
   {
      $FiberID = spl_object_id($Fiber);
      $Binding = $this->Bindings[$FiberID] ?? null;
      $Token = $Binding['Token'] ?? null;

      if ($Token?->check() === true) {
         $this->evict($Fiber, $Token);

         return false;
      }

      if ($Fiber->isTerminated() || $value === self::DETACH) {
         if ($Fiber->isTerminated()) {
            $Token?->cancel();
         }
         $this->evict($Fiber, $Token);

         return false;
      }

      // @ I/O-bound: register socket in stream_select + map to Fiber
      $queued = $this->queue($Fiber, $value, $flag);
      // ? Selector signal callbacks may settle the captured token.
      if ($Token?->check() === true) { // @phpstan-ignore identical.alwaysFalse
         $this->evict($Fiber, $Token);

         return false;
      }
      if ($queued === true) {
         return true;
      }
      if ($queued === false) {
         return $this->reject($Fiber);
      }

      // @ Tick-based: resume every iteration
      $this->park($Fiber);

      // :
      return true;
   }

   /**
    * Bind callbacks around every scheduled execution segment of a Fiber.
    *
    * @param Fiber<mixed,mixed,mixed,mixed> $Fiber
    */
   public function bind (Fiber $Fiber, Closure $Enter, Closure $Leave): void
   {
      $FiberID = spl_object_id($Fiber);
      $Token = Cancellation::fetch($Fiber);
      $this->Bindings[$FiberID] = [
         'Enter' => $Enter,
         'Leave' => $Leave,
         'Token' => $Token,
      ];

      if ($Token === null) {
         return;
      }

      // ! Keep the token observer from retaining a pooled Fiber after every
      //   external owner alias is gone. Identity is checked again by evict()
      //   so a late old-generation observer cannot remove a reused Fiber.
      $Reference = WeakReference::create($Fiber);
      $Token->observe(function (
         Cancellation $Observed,
         bool $cancelled,
      ) use ($Reference, $Token): void {
         if ($Observed !== $Token) {
            return;
         }

         $Fiber = $Reference->get();
         if ($Fiber !== null) {
            $this->evict($Fiber, $Token);
         }
      });
   }

   /**
    * Remember one awaiting-queue location for a Fiber's current generation.
    *
    * @param Fiber<mixed,mixed,mixed,mixed> $Fiber
    */
   private function mark (Fiber $Fiber, int $id): void
   {
      $Waits = $this->Waits ??= new WeakMap;

      $locations = $Waits[$Fiber] ?? [];
      $locations[$id] = true;
      $Waits[$Fiber] = $locations;
   }

   /**
    * Queue one Fiber for tick-based resumption, remembering where it landed.
    *
    * @param Fiber<mixed,mixed,mixed,mixed> $Fiber
    */
   private function park (Fiber $Fiber): void
   {
      $this->Fibers[] = $Fiber;

      $Ticks = $this->Ticks ??= new WeakMap;

      $indexes = $Ticks[$Fiber] ?? [];
      $indexes[array_key_last($this->Fibers)] = true;
      $Ticks[$Fiber] = $indexes;

      $this->weigh();
   }

   /**
    * Raise the scheduled-waiter high-water mark to the current occupancy.
    *
    * Three O(1) counts on the admission path only. A worker that defers
    * nothing never reaches it — `schedule()` is the sole caller's caller —
    * so the plaintext path pays nothing for it.
    */
   private function weigh (): void
   {
      $parked = count($this->Fibers)
         + count($this->awaitingReads)
         + count($this->awaitingWrites);

      if ($parked > $this->parked) {
         $this->parked = $parked;
      }
   }

   /**
    * Evict one exact scheduled generation from every reactor queue.
    *
    * The token identity guard is essential for pooled Fibers: cancellation
    * from an earlier generation must not remove the same Fiber after it has
    * been rebound to a new response. Persistent socket owners are retained
    * when their last transient Fiber waiter is removed.
    *
    * @param Fiber<mixed,mixed,mixed,mixed> $Fiber
    */
   private function evict (Fiber $Fiber, null|Cancellation $Token): bool
   {
      $FiberID = spl_object_id($Fiber);
      $Binding = $this->Bindings[$FiberID] ?? null;
      if ($Binding === null) {
         if ($Token === null) {
            return false;
         }

         // ? A selector-admission callback may settle this generation after
         //   queue() begins but before it appends the waiter. Settlement
         //   unpublishes the weak alias and its observer removes the binding;
         //   the terminal captured token still owns that late queue entry.
         //   Conversely, a different current alias proves that the pooled
         //   Fiber has already been rebound and must remain untouched.
         $Current = Cancellation::fetch($Fiber);
         if (
            ($Current !== null && $Current !== $Token)
            || ($Current === null && $Token->check() === false)
         ) {
            return false;
         }
      }
      else {
         if ($Binding['Token'] !== $Token) {
            return false;
         }
         unset($this->Bindings[$FiberID]);
      }

      // @ Only the locations this Fiber actually entered, never a sweep of
      //   every parked waiter. A location consumed by readiness dispatch,
      //   expiry or release is stale — one lookup that matches nothing — and
      //   the identity check keeps another generation's entry untouched.
      $Ticks = $this->Ticks;
      $indexes = $Ticks === null ? null : ($Ticks[$Fiber] ?? null);
      if ($indexes !== null) {
         unset($Ticks[$Fiber]);
         foreach ($indexes as $index => $_) {
            if (($this->Fibers[$index] ?? null) === $Fiber) {
               unset($this->Fibers[$index]);
            }
         }
      }

      $Waits = $this->Waits;
      $locations = $Waits === null ? null : ($Waits[$Fiber] ?? null);
      if ($locations === null) {
         return true;
      }
      unset($Waits[$Fiber]);

      foreach ($locations as $id => $_) {
         if (isSet($this->awaitingReads[$id])) {
            foreach ($this->awaitingReads[$id] as $index => $Queued) {
               if ($Queued === $Fiber) {
                  unset($this->awaitingReads[$id][$index]);
               }
            }
            if ($this->awaitingReads[$id] === []) {
               unset($this->awaitingReads[$id]);
               unset($this->awaitingReadDeadlines[$id]);
               if (
                  isSet($this->reading[$id]) === false
                  && isSet($this->connecting[$id]) === false
               ) {
                  unset($this->reads[$id]);
               }
            }
         }

         if (isSet($this->awaitingWrites[$id])) {
            foreach ($this->awaitingWrites[$id] as $index => $Queued) {
               if ($Queued === $Fiber) {
                  unset($this->awaitingWrites[$id][$index]);
               }
            }
            if ($this->awaitingWrites[$id] === []) {
               unset($this->awaitingWrites[$id]);
               unset($this->awaitingWriteDeadlines[$id]);
               if (isSet($this->writing[$id]) === false) {
                  unset($this->writes[$id]);
               }
            }
         }
      }

      return true;
   }

   /**
    * Snapshot exact Fiber generations before dispatching one mutable batch.
    *
    * @param array<int,Fiber<mixed,mixed,mixed,mixed>> $Fibers
    *
    * @return array<int,array{
    *    Fiber:Fiber<mixed,mixed,mixed,mixed>,
    *    Token:null|Cancellation
    * }>
    */
   private function capture (array $Fibers): array
   {
      $Generations = [];
      foreach ($Fibers as $id => $Fiber) {
         $Binding = $this->Bindings[spl_object_id($Fiber)] ?? null;
         $Generations[$id] = [
            'Fiber' => $Fiber,
            'Token' => $Binding['Token'] ?? null,
         ];
      }

      return $Generations;
   }

   /**
    * Queue a Fiber by explicit stream readiness.
    *
    * @param Fiber<mixed, mixed, mixed, mixed> $Fiber
    * @param mixed $value
    *
    * @return null|bool null for tick-compatible non-I/O values, true when
    *    admitted, false when an explicit I/O wait was rejected without
    *    mutating selector state.
    */
   private function queue (Fiber $Fiber, mixed $value = null, int $flag = self::SCHEDULE_READ): null|bool
   {
      $deadline = 0.0;
      $readiness = $value instanceof Readiness;

      if ($readiness) {
         /** @var Readiness $Readiness */
         $Readiness = $value;
         $flag = $Readiness->flag;
         $deadline = $Readiness->deadline;
         $value = $Readiness->socket;
      }

      if (is_resource($value) === false) {
         return $readiness || get_debug_type($value) === 'resource (closed)'
            ? false
            : null;
      }
      if ($flag !== self::SCHEDULE_READ && $flag !== self::SCHEDULE_WRITE) {
         return false;
      }

      $Socket = $value;
      $id = (int) $Socket;

      if ($flag === self::SCHEDULE_WRITE) {
         foreach ($this->awaitingWrites[$id] ?? [] as $Queued) {
            if ($Queued === $Fiber) {
               return true;
            }
         }
         if (
            isset($this->writes[$id]) === false
            && (
               count($this->writes) >= 1000
               || $this->check($Socket, self::EVENT_WRITE) === false
            )
         ) {
            return false;
         }

         $this->awaitingWrites[$id][] = $Fiber;
         $this->mark($Fiber, $id);
         $this->track($this->awaitingWriteDeadlines, $id, $deadline);
         $this->writes[$id] = $Socket;
         $this->weigh();

         return true;
      }

      foreach ($this->awaitingReads[$id] ?? [] as $Queued) {
         if ($Queued === $Fiber) {
            return true;
         }
      }
      if (
         isset($this->reads[$id]) === false
         && (
            count($this->reads) >= 1000
            || $this->check($Socket, self::EVENT_READ) === false
         )
      ) {
         return false;
      }

      $this->awaitingReads[$id][] = $Fiber;
      $this->mark($Fiber, $id);
      $this->track($this->awaitingReadDeadlines, $id, $deadline);
      $this->reads[$id] = $Socket;
      $this->weigh();

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

      // ! Read each clock only for its consumers: the wall clock serves the
      //   Timers set plus the awaiting-I/O deadlines, the monotonic clock
      //   serves MonotonicTimers. A lone monotonic deadline (the common
      //   benchmark-worker shape) then costs one clock read per wakeup.
      $wall = $this->Timers !== []
         || $this->awaitingReadDeadlines !== []
         || $this->awaitingWriteDeadlines !== [];
      $now = $wall ? microtime(true) : 0.0;
      $nowMonotonic = $this->MonotonicTimers !== [] ? (int) hrtime(true) : 0;
      $wait = null;
      $fired = false;

      foreach ($this->Timers as $ID => $Timer) {
         if ($Timer['deadline'] > $now) {
            continue;
         }

         unset($this->Timers[$ID]);
         $fired = true;
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
         $fired = true;
         try {
            ($Timer['Callback'])();
         }
         catch (Throwable) {
            // One failed timeout callback must not tear down the event loop.
         }
      }

      // @ Callbacks may cancel or register timers. Compute the next wait from
      //   the post-callback sets so a newly-nearest timer is never overslept.
      //   Without a fired callback the sets are unchanged and the elapsed time
      //   is nanoseconds — reuse the first reads instead of two more.
      if ($fired) {
         $now = $this->Timers !== []
            || $this->awaitingReadDeadlines !== []
            || $this->awaitingWriteDeadlines !== []
            ? microtime(true)
            : $now;
         $nowMonotonic = $this->MonotonicTimers !== []
            ? (int) hrtime(true)
            : $nowMonotonic;
      }
      foreach ($this->Timers as $Timer) {
         $this->bound(max(0.0, $Timer['deadline'] - $now), $wait);
      }
      foreach ($this->MonotonicTimers as $Timer) {
         $this->bound(
            max(0.0, ($Timer['deadline'] - $nowMonotonic) / 1_000_000_000),
            $wait
         );
      }

      $this->expire(
         $this->awaitingReads,
         $this->reads,
         $this->awaitingReadDeadlines,
         $now,
         self::SCHEDULE_READ,
      );
      $this->expire(
         $this->awaitingWrites,
         $this->writes,
         $this->awaitingWriteDeadlines,
         $now,
         self::SCHEDULE_WRITE,
      );
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
   private function expire (
      array &$Fibers,
      array &$sockets,
      array &$deadlines,
      float $now,
      int $flag,
   ): void {
      foreach ($deadlines as $id => $deadline) {
         if ($deadline <= 0.0) {
            continue;
         }

         if ($deadline > $now) {
            continue;
         }

         $Queued = $Fibers[$id] ?? [];

         unset($Fibers[$id]);
         unset($deadlines[$id]);
         if (
            $flag === self::SCHEDULE_READ
            ? (
               isSet($this->reading[$id]) === false
               && isSet($this->connecting[$id]) === false
            )
            : isSet($this->writing[$id]) === false
         ) {
            unset($sockets[$id]);
         }

         foreach ($this->capture($Queued) as $Generation) {
            $this->resume(
               $Generation['Fiber'],
               $Generation['Token'],
            );
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
         $Binding = $this->Bindings[spl_object_id($Fiber)] ?? null;
         $Token = $Binding['Token'] ?? null;
         if ($Token?->check() === true) {
            $this->evict($Fiber, $Token);

            continue;
         }

         if ($Fiber->isSuspended()) {
            $this->park($Fiber);
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
   private function resume (
      Fiber $Fiber,
      null|Cancellation $Expected = null,
   ): void
   {
      $FiberID = spl_object_id($Fiber);
      $Binding = $this->Bindings[$FiberID] ?? null;
      $Token = $Binding['Token'] ?? null;

      if ($Expected !== null && $Token !== $Expected) {
         $this->evict($Fiber, $Expected);

         return;
      }
      if ($Token?->check() === true) {
         $this->evict($Fiber, $Token);

         return;
      }
      if ($Fiber->isSuspended() === false) {
         return;
      }

      $value = $this->Bindings === []
         ? $Fiber->resume()
         : $this->advance($Fiber);

      // ? Resumed user code may have settled the captured token.
      if ($Token?->check() === true) { // @phpstan-ignore identical.alwaysFalse
         $this->evict($Fiber, $Token);

         return;
      }
      if ($Fiber->isTerminated()) {
         $this->evict($Fiber, $Token);

         return;
      }

      // ? Pooled Fiber parked itself (job finished) — drop, do not requeue
      if ($value === self::DETACH) {
         $this->evict($Fiber, $Token);

         return;
      }

      $queued = $this->queue($Fiber, $value);
      // ? Selector signal callbacks may settle the captured token.
      if ($Token?->check() === true) { // @phpstan-ignore identical.alwaysFalse
         $this->evict($Fiber, $Token);

         return;
      }
      if ($queued === null) {
         $this->park($Fiber);
      }
      else if ($queued === false) {
         $this->reject($Fiber);
      }
   }

   /**
    * Fail one rejected I/O wait inside its suspended Fiber.
    *
    * Delivering the error at the wait point lets the Fiber run its own
    * exception and finally lifecycle. If it catches the rejection and
    * suspends again, inspect that next target once. A second rejected target
    * is dropped instead of recursively injecting errors without a bound.
    *
    * @param Fiber<mixed,mixed,mixed,mixed> $Fiber
    *
    * @return bool True when the Fiber was rescheduled after handling the
    *    rejection; false when it terminated, detached or was dropped.
    */
   private function reject (Fiber $Fiber): bool
   {
      $FiberID = spl_object_id($Fiber);
      $Binding = $this->Bindings[$FiberID] ?? null;
      $Token = $Binding['Token'] ?? null;

      if ($Token?->check() === true) {
         $this->evict($Fiber, $Token);

         return false;
      }
      if ($Fiber->isSuspended() === false) {
         $Token?->cancel();
         $this->evict($Fiber, $Token);

         return false;
      }

      $Error = new RuntimeException(
         'Fiber I/O resource failed selector admission.'
      );

      try {
         $value = $this->advance($Fiber, $Error);
      }
      catch (Throwable) {
         $Token?->cancel();
         $this->evict($Fiber, $Token);

         return false;
      }

      // ? Rejected user code may have settled the captured token.
      if ($Token?->check() === true) { // @phpstan-ignore identical.alwaysFalse
         $this->evict($Fiber, $Token);

         return false;
      }
      if ($Fiber->isTerminated()) {
         $Token?->cancel();
         $this->evict($Fiber, $Token);

         return false;
      }
      if ($value === self::DETACH) {
         $this->evict($Fiber, $Token);

         return false;
      }

      $queued = $this->queue($Fiber, $value);
      // ? Selector signal callbacks may settle the captured token.
      if ($Token?->check() === true) { // @phpstan-ignore identical.alwaysFalse
         $this->evict($Fiber, $Token);

         return false;
      }
      if ($queued === true) {
         return true;
      }
      if ($queued === null) {
         $this->park($Fiber);

         return true;
      }

      $Token?->cancel();
      $this->evict($Fiber, $Token);

      return false;
   }

   /**
    * Resume one Fiber within its optional execution-segment binding.
    *
    * @param Fiber<mixed,mixed,mixed,mixed> $Fiber
    */
   private function advance (Fiber $Fiber, null|Throwable $Throwable = null): mixed
   {
      $FiberID = spl_object_id($Fiber);
      $Binding = $this->Bindings[$FiberID] ?? null;
      $Token = $Binding['Token'] ?? null;

      if ($Token?->check() === true) {
         $this->evict($Fiber, $Token);

         return self::DETACH;
      }

      if ($Binding === null) {
         return $Throwable === null
            ? $Fiber->resume()
            : $Fiber->throw($Throwable);
      }

      $failed = false;
      try {
         ($Binding['Enter'])();
         // ? The context-enter callback may settle the captured token.
         if ($Token?->check() === true) { // @phpstan-ignore identical.alwaysFalse
            return self::DETACH;
         }

         return $Throwable === null
            ? $Fiber->resume()
            : $Fiber->throw($Throwable);
      }
      catch (Throwable $Throwable) {
         $failed = true;
         throw $Throwable;
      }
      finally {
         try {
            ($Binding['Leave'])();
         }
         catch (Throwable $Throwable) {
            $failed = true;
            throw $Throwable;
         }
         finally {
            if ($failed) {
               $Token?->cancel();
               $this->evict($Fiber, $Token);
            }
            // ? Executed user code may have settled the captured token.
            else if ($Token?->check() === true) { // @phpstan-ignore identical.alwaysFalse
               $this->evict($Fiber, $Token);
            }
         }
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
      $this->Waits = null;
      $this->Ticks = null;
      // ! An observation of the drain that just ended, not a retained
      //   reference: a reused reactor must report its own peak, not inherit one.
      $this->parked = 0;
      $Bindings = $this->Bindings;
      $this->Bindings = [];
      foreach ($Bindings as $Binding) {
         $Token = $Binding['Token'];
         if ($Token === null || $Token->check()) {
            continue;
         }

         try {
            $Token->cancel();
         }
         catch (Throwable) {
            // Scheduler destruction must continue through every generation.
         }
      }
      $this->Timers = [];
      $this->MonotonicTimers = [];

      // # Loop
      $this->loop = false;
   }
}
