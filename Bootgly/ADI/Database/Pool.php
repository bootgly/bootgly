<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Database;


use function array_key_first;
use function array_shift;
use function count;
use function is_resource;
use function microtime;
use function mt_rand;
use function spl_object_id;
use function stream_select;
use RuntimeException;
use WeakMap;

use Bootgly\ACI\Events\Scheduler;
use Bootgly\ADI\Database\Config;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Database\Connection\ConnectionStates;
use Bootgly\ADI\Database\Driver;
use Bootgly\ADI\Database\Drivers;
use Bootgly\ADI\Database\Operation;
use Bootgly\ADI\Database\Operation\OperationStates;


/**
 * Async database connection pool.
 */
class Pool
{
   public const int DEFAULT_FAILURES = 2;
   public const float DEFAULT_RETRY = 5.0;
   public const float DEFAULT_JITTER = 0.25;

   // * Config
   public Config $Config;
   public Connection $Connection;
   public int $min;
   public int $max;
   /** @var class-string<Drivers> */
   public string $drivers;

   // * Data
   /** @var array<int,Connection> */
   public array $idle = [];
   /** @var array<int,Connection> */
   public array $busy = [];
   /** @var array<int,Operation> */
   public array $pending = [];
   public int $created = 0;
   public private(set) int $failures = 0;
   public private(set) float $retry = 0.0;

   // * Metadata
   public bool $healthy {
      get {
         return $this->retry <= 0.0 || microtime(true) >= $this->retry;
      }
   }
   /** @var array<int,true> */
   private array $counted = [];
   // @ Operations whose claim on a connection is already settled. A driver may
   //   keep a finished operation's FIFO slot until the message that terminates
   //   it arrives, so the same object reaches release() a second time when that
   //   message retires it — by which point the connection belongs to somebody
   //   else, and re-running the compensation takes their reservation with it.
   /** @var WeakMap<Operation,true> */
   private WeakMap $settled;
   /** @var array<int,true> */
   private array $locked = [];
   // @ Round-robin cursor for co-locating pipelined operations across connections.
   private int $cursor = 0;


   /**
    * @param class-string<Drivers> $drivers
    */
   public function __construct (Config $Config, Connection $Connection, string $drivers = Drivers::class)
   {
      // * Config
      $this->Config = $Config;
      $this->Connection = $Connection;
      $this->settled = new WeakMap();
      $this->min = $Config->pool['min'];
      $this->max = $Config->pool['max'];
      $this->drivers = $drivers;
   }

   /**
    * Attach an existing ready connection to the idle pool.
    */
   public function attach (Connection $Connection): self
   {
      $id = spl_object_id($Connection);
      $this->track($Connection);

      $this->idle[$id] = $Connection;

      return $this;
   }

   /**
    * Advance an operation through its assigned protocol and release on finish.
    */
   public function advance (Operation $Operation): Operation
   {
      $Pool = $Operation->Pool;

      if ($Pool !== null && $Pool !== $this) {
         return $Pool->advance($Operation);
      }

      if ($this->fallback($Operation)) {
         return $Operation;
      }

      if ($Operation->expire()) {
         // @ The driver still owns whatever the server is sending for this
         //   operation: let it reconcile the wire before the connection is
         //   handed to anyone else and before fallback() revives the object.
         $Protocol = $Operation->Protocol;
         $Protocol?->abandon($Operation);

         // @ Reconciling may have torn the session down, which fails every
         //   sibling on it and hands them back through the driver. They must be
         //   collected here, while the connection they were on is still the one
         //   being released: left for a later advance, their release lands on
         //   whatever connection the pool has rebuilt since and drops that one.
         if ($Protocol !== null) {
            $this->drain($Protocol, $Operation);
         }

         $this->forget($Operation);
         $this->release($Operation);

         $this->fallback($Operation);

         return $Operation;
      }

      if ($Operation->state === OperationStates::Pending) {
         $this->assign($Operation);
         $this->fallback($Operation);

         return $Operation;
      }

      // @ assign() always sets a Driver on Pending → !Pending operations.
      //   The null guard covers raw Operations that bypass assign().
      $Protocol = $Operation->Protocol;

      if ($Protocol === null) {
         $Operation = $this->assign($Operation);
         $Protocol = $Operation->Protocol;

         if ($Protocol === null) {
            return $Operation;
         }
      }

      $Protocol->advance($Operation);
      $released = $this->drain($Protocol, $Operation);

      if ($Operation->finished && $released === false) {
         $this->release($Operation);
      }

      $this->fallback($Operation);

      return $Operation;
   }

   /**
    * Wait for one operation to finish using its readiness hints.
    */
   public function wait (Operation $Operation): Operation
   {
      $Pool = $Operation->Pool;

      if ($Pool !== null && $Pool !== $this) {
         return $Pool->wait($Operation);
      }

      while (true) {
         $this->advance($Operation);

         $Readiness = $Operation->Readiness;
         if ($Operation->finished) {
            break;
         }

         // ? `Pending` is a legitimate parked state, not a missing one: assign()
         //   found no capacity and parked the operation, so it carries no
         //   Connection, Protocol or Readiness BY DESIGN. Reading that as a hard
         //   failure and throwing left the operation in `$pending` with the pool
         //   still holding a strong reference — and promote() then put the
         //   command on the wire once capacity freed, AFTER the caller's
         //   compensating rollback and outside its transaction.
         //
         //   Waiting for capacity instead is not available here. wait() is the
         //   synchronous API: while it blocks, nothing advances the operations
         //   holding the connections, and only they can free one. Measured on a
         //   saturated pool, a select() over `$busy` returns immediately on every
         //   readable undrained reply and burns the whole deadline at ~50% CPU
         //   before failing anyway, while a synchronous driver has no selectable
         //   socket at all. So the operation leaves the pool here and fails with
         //   the cause the caller can act on.
         if ($Operation->state === OperationStates::Pending) {
            $Pool = $Operation->Pool;

            // ?: Fallback re-dispatched this operation to another pool mid-wait —
            //    it is that pool's to satisfy or refuse, and `$this->pending`
            //    describes the old one.
            if ($Pool !== null && $Pool !== $this) {
               return $Pool->wait($Operation);
            }

            $this->forget($Operation);
            $Operation->fail('Database pool has no capacity for the operation.');

            continue;
         }

         if ($Readiness === null) {
            $Pool = $Operation->Pool;

            // ?: Fallback re-dispatched this operation to another pool mid-wait —
            //    the new pool arms readiness on its next advance.
            if ($Pool !== null && $Pool !== $this) {
               return $Pool->wait($Operation);
            }

            throw new RuntimeException('Database operation did not provide readiness.');
         }

         $read = [];
         $write = [];
         $except = [];

         if ($Readiness->flag === Scheduler::SCHEDULE_READ) {
            $read[] = $Readiness->socket;
         }
         else {
            $write[] = $Readiness->socket;
         }

         $selected = stream_select($read, $write, $except, 1, 0);
         if ($selected === false) {
            throw new RuntimeException('Database operation readiness wait failed.');
         }
      }

      if ($Operation->error !== null) {
         throw new RuntimeException($Operation->error);
      }

      return $Operation;
   }

   /**
    * Cancel one operation through its assigned protocol.
    */
   public function cancel (Operation $Operation): Operation
   {
      $Pool = $Operation->Pool;

      if ($Pool !== null && $Pool !== $this) {
         return $Pool->cancel($Operation);
      }

      $Protocol = $Operation->Protocol;

      if ($Protocol === null) {
         // ? A parked operation has no protocol, and failing it while the pool
         //   still holds it in `pending` leaves promote() free to shift it once
         //   capacity frees. `pending` carries live operations only, which is
         //   what wait() and assign() both maintain.
         $this->forget($Operation);

         // ! And it must never come back. `cancelled` is the flag fallback()
         //   reads to decide whether an operation may be revived, so leaving it
         //   false let a later await() retry the very statement the caller
         //   cancelled — measured executing on the server.
         $Operation->cancelled = true;

         return $Operation->fail('Database operation has no protocol to cancel.');
      }

      $Operation = $Protocol->cancel($Operation);

      // ? The cancel never reached the server: the operation is finished while
      //   the driver still owns the answer the server keeps sending. That is
      //   the same state an elapsed deadline leaves behind, so it takes the
      //   same route — reconcile the wire, then take the connection back.
      if ($Operation->finished && $Operation->cancelled === false) {
         $Protocol->abandon($Operation);

         // @ Same reason as the expire branch: a teardown here hands the
         //   siblings back, and their release belongs to this connection.
         $this->drain($Protocol, $Operation);

         $this->forget($Operation);
         $this->release($Operation);
      }

      return $Operation;
   }

   /**
    * Drain protocol-completed operations and release failures first.
    */
   private function drain (Driver $Protocol, Operation $Operation): bool
   {
      $Completed = $Protocol->drain();
      $released = false;

      // @ Failure-first release is deliberate: a failed sibling may be the
      //   operation that decides whether the shared connection is reusable,
      //   while later successful releases remain idempotent.
      foreach ($Completed as $CompletedOperation) {
         if ($CompletedOperation->state !== OperationStates::Failed) {
            continue;
         }

         if ($CompletedOperation === $Operation) {
            $released = true;
         }

         $this->release($CompletedOperation);
      }

      foreach ($Completed as $CompletedOperation) {
         if ($CompletedOperation->state === OperationStates::Failed) {
            continue;
         }

         if ($CompletedOperation === $Operation) {
            $released = true;
         }

         $this->release($CompletedOperation);
      }

      return $released;
   }

   /**
    * Release an operation connection back to the pool when reusable.
    */
   public function release (Operation $Operation): self
   {
      $Pool = $Operation->Pool;

      if ($Pool !== null && $Pool !== $this) {
         $Pool->release($Operation);

         return $this;
      }

      $Connection = $Operation->Connection;

      if ($Connection === null) {
         return $this;
      }

      // ? Its claim was settled when it finished. Running the compensation again
      //   now applies it to whatever holds this connection since.
      if (isset($this->settled[$Operation])) {
         return $this;
      }

      $this->settled[$Operation] = true;

      $id = spl_object_id($Connection);
      $Protocol = $Operation->Protocol;

      // ? The driver this operation was assigned to is no longer the one on the
      //   connection: its session was torn down and the pool has since rebuilt
      //   on the same Connection object. The claim died with that session, and
      //   honouring it here drops a connection somebody else is holding.
      if ($Protocol !== null && $Connection->Protocol !== null && $Protocol !== $Connection->Protocol) {
         return $this;
      }

      // ? The reservation is this operation's to release, and the intent lives
      //   on the object: deferring it because the driver still holds a sibling
      //   loses it for good — nobody re-runs this release, and the sibling's own
      //   release then parks the connection as reserved forever.
      if ($Operation->unlock || ($Operation->lock && $Operation->state === OperationStates::Failed)) {
         $this->unlock($Connection);
      }

      $alive = is_resource($Connection->socket);
      $usable = $alive && $Connection->state === ConnectionStates::Ready;

      // ? A pipelined reply is still owed, so the connection stays busy until
      //   whoever is reading drains the FIFO — but only while the socket can
      //   still deliver it. An unusable one owes an answer it can never bring,
      //   and everything that frees the slot lives below this gate: the
      //   reservation, the busy entry, drop() and promote(). Gating on it there
      //   left the pool counting a dead connection against `max` for good.
      if ($usable && $Protocol !== null && $Protocol->check()) {
         return $this;
      }

      unset($this->busy[$id]);

      if ($usable === false) {
         unset($this->idle[$id]);
         unset($this->locked[$id]);

         if ($alive) {
            $Connection->disconnect();
         }

         $this->drop($Connection);

         $this->promote();

         return $this;
      }

      if ($Operation->state === OperationStates::Finished) {
         $this->recover();
      }

      if (isset($this->locked[$id])) {
         $this->busy[$id] = $Connection;

         return $this;
      }

      // ? The pool dropped this connection and a driver has since reconnected
      //   it on its own. It is not the pool's to hand out any more: admitting
      //   it would serve work from a connection nothing counts against `max`,
      //   and the cap would then describe fewer sockets than are open.
      //
      //   Refusing alone is not enough — nothing else would ever close it, and
      //   the socket would outlive the pool's knowledge of it. The unusable
      //   branch above disconnects for the same reason, so this does too.
      if (isset($this->counted[$id])) {
         $this->idle[$id] = $Connection;
      }
      else {
         $Connection->disconnect();
      }

      $this->promote();

      return $this;
   }

   /**
    * Assign an available connection and protocol to an operation.
    */
   public function assign (Operation $Operation): Operation
   {
      // ? A finished operation must never be re-prepared — on synchronous
      //   drivers prepare() executes, so a cancelled or refused statement
      //   would run anyway.
      if ($Operation->finished) {
         return $Operation;
      }

      $Operation->Pool = $this;
      $Connection = $this->acquire($Operation->Connection, $Operation->lock === false);

      if ($Connection === null) {
         // ? A pin the pool can no longer provide is never satisfied by waiting:
         //   acquire() decides a pinned request without ever consulting idle or
         //   max, so no capacity, no release and no promote() can change the
         //   answer. Parking it would queue an operation nothing can assign —
         //   and promote() would keep reconsidering it for as long as the pool
         //   has room.
         if ($Operation->Connection !== null) {
            $this->forget($Operation);
            $Operation->fail('Database operation lost the connection it was pinned to.');
            $this->release($Operation);

            return $Operation;
         }

         $Operation->state = OperationStates::Pending;

         if ($this->check($Operation) === false) {
            $this->pending[] = $Operation;
         }

         return $Operation;
      }

      // ? A pending operation may be assigned through Pool::advance() before
      //   promote() shifts it — forget it so it is never assigned twice
      //   (a second assign() re-prepares and re-sends the wire command).
      $this->forget($Operation);

      $Protocol = $this->create($Connection);
      unset($this->settled[$Operation]);

      $Operation->Connection = $Connection;
      $Operation->Protocol = $Protocol;

      if ($Operation->lock) {
         $this->lock($Connection);
      }

      $Operation = $Protocol->prepare($Operation);

      if ($Operation->finished) {
         $this->release($Operation);
      }

      return $Operation;
   }

   /**
    * Quarantine this pool after a failed fallback.
    */
   public function penalize (float $seconds = self::DEFAULT_RETRY, int $failures = self::DEFAULT_FAILURES, float $jitter = self::DEFAULT_JITTER): self
   {
      $this->failures++;

      if ($this->failures < $failures) {
         return $this;
      }

      $spread = 0.0;
      $limit = (int) ($jitter * 1000000);

      if ($limit > 0) {
         $spread = mt_rand(1, $limit) / 1000000;
      }

      $this->retry = microtime(true) + $seconds + $spread;

      return $this;
   }

   /**
    * Clear replica health penalty after a successful operation.
    */
   public function recover (): self
   {
      $this->failures = 0;
      $this->retry = 0.0;

      return $this;
   }

   /**
    * Reserve one pool connection for an owner such as a SQL transaction.
    */
   public function lock (Connection $Connection): self
   {
      $id = spl_object_id($Connection);

      if (isset($this->counted[$id])) {
         $this->locked[$id] = true;
      }

      return $this;
   }

   /**
    * Release a reserved pool connection back to normal pool scheduling.
    */
   public function unlock (Connection $Connection): self
   {
      unset($this->locked[spl_object_id($Connection)]);

      return $this;
   }

   /**
    * Acquire an idle connection or reserve capacity for a new one.
    */
   private function acquire (null|Connection $Pinned = null, bool $pipelineable = true): null|Connection
   {
      if ($Pinned !== null) {
         $id = spl_object_id($Pinned);

         if (isset($this->counted[$id]) === false || is_resource($Pinned->socket) === false) {
            return null;
         }

         unset($this->idle[$id]);
         $this->busy[$id] = $Pinned;

         return $Pinned;
      }

      $id = array_key_first($this->idle);

      if ($id !== null) {
         $Connection = $this->idle[$id];
         unset($this->idle[$id]);
         $this->busy[$id] = $Connection;

         return $Connection;
      }

      if ($this->created >= $this->max) {
         // @ Pool exhausted — co-locate this operation on a ready busy
         //   connection so the driver pipelines it instead of queueing it
         //   pending. Exclusive operations (transactions) never co-locate.
         if ($pipelineable === false) {
            return null;
         }

         /** @var array<int,Connection> $Eligible */
         $Eligible = [];

         foreach ($this->busy as $id => $Connection) {
            if (isset($this->locked[$id])) {
               continue;
            }

            $Protocol = $Connection->Protocol;

            if (
               $Protocol !== null
               && $Connection->connected
               && $Connection->state === ConnectionStates::Ready
               && is_resource($Connection->socket)
            ) {
               $Eligible[] = $Connection;
            }
         }

         if ($Eligible === []) {
            return null;
         }

         $Connection = $Eligible[$this->cursor % count($Eligible)];
         $this->cursor++;

         return $Connection;
      }

      $Connection = $this->created === 0
         ? $this->Connection
         : new Connection($this->Config);
      $id = spl_object_id($Connection);
      $this->track($Connection);
      $this->busy[$id] = $Connection;

      return $Connection;
   }
   /**
    * Track one pool-owned connection.
    */
   private function track (Connection $Connection): void
   {
      $id = spl_object_id($Connection);

      if (isset($this->counted[$id])) {
         return;
      }

      $this->counted[$id] = true;
      $this->created++;
   }

   /**
    * Drop one pool-owned connection from bookkeeping.
    */
   private function drop (Connection $Connection): void
   {
      $id = spl_object_id($Connection);

      if (isset($this->counted[$id]) === false) {
         return;
      }

      unset($this->counted[$id]);

      if ($this->created > 0) {
         $this->created--;
      }
   }

   /**
    * Check whether an operation is already pending.
    */
   private function check (Operation $Operation): bool
   {
      foreach ($this->pending as $Pending) {
         if ($Pending === $Operation) {
            return true;
         }
      }

      return false;
   }

   /**
    * Forget one pending operation.
    */
   private function forget (Operation $Operation): void
   {
      foreach ($this->pending as $id => $Pending) {
         if ($Pending === $Operation) {
            unset($this->pending[$id]);
         }
      }
   }

   /**
    * Retry a failed operation through its fallback pool once.
    */
   private function fallback (Operation $Operation): bool
   {
      $FallbackPool = $Operation->FallbackPool;

      if ($FallbackPool === null || $FallbackPool === $this || $Operation->fallback || $Operation->cancelled || $Operation->state !== OperationStates::Failed) {
         return false;
      }

      if ($Operation->quarantine) {
         $this->penalize();
      }

      $Operation->fallback = true;
      $Operation->retry();
      $FallbackPool->assign($Operation);

      return true;
   }

   /**
    * Create a protocol instance bound to a connection.
    */
   private function create (Connection $Connection): Driver
   {
      $Protocol = $Connection->Protocol;

      if ($Protocol !== null) {
         return $Protocol;
      }

      $drivers = $this->drivers;
      $Drivers = new $drivers($this->Config, $Connection);
      $Driver = $Drivers->fetch($this->Config->driver);

      $Connection->bind($Driver);

      return $Driver;
   }

   /**
    * Promote pending operations while capacity is available.
    */
   private function promote (): void
   {
      while ($this->pending !== [] && ($this->idle !== [] || $this->created < $this->max)) {
         $Operation = array_shift($this->pending);

         if ($Operation->expire()) {
            continue;
         }

         $this->assign($Operation);
         $this->advance($Operation);
      }
   }
}
