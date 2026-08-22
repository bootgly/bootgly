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


use const E_WARNING;
use function array_key_first;
use function array_shift;
use function count;
use function is_callable;
use function is_resource;
use function microtime;
use function mt_rand;
use function restore_error_handler;
use function set_error_handler;
use function spl_object_id;
use function str_contains;
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

      // ! Whether this operation ever reached the wire, read before expire()
      //   fails it and the answer is lost.
      $sent = $Operation->state !== OperationStates::Queued;

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
         $this->settle($Operation, $sent);

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

      $handling = false;
      $interrupted = false;
      $selecting = false;
      $PreviousHandler = null;

      try {
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

            // ! Signals are an ordinary part of the worker lifecycle. Linux
            //   interrupts select() when a no-restart handler runs, but neither
            //   the database operation nor its readiness changed: throwing here
            //   handed API stores an unfinished write which the server later
            //   committed. Install one handler lazily per wait(), rather than on
            //   every readiness turn; synchronous drivers avoid handler setup,
            //   and a multi-read result pays that setup only once.
            if ($handling === false) {
               $PreviousHandler = set_error_handler(
                  static function (
                     int $level,
                     string $message,
                     string $file,
                     int $line
                  ) use (&$interrupted, &$selecting, &$PreviousHandler): bool {
                     if (
                        $selecting // @phpstan-ignore-line: mutated around stream_select()
                        && $level === E_WARNING
                        && str_contains($message, 'stream_select()')
                        && (
                           str_contains($message, 'Unable to select [4]')
                           || str_contains($message, 'Interrupted system call')
                        )
                     ) {
                        $interrupted = true;

                        return true;
                     }

                     if (is_callable($PreviousHandler)) {
                        return (bool) $PreviousHandler($level, $message, $file, $line);
                     }

                     return false;
                  }
               );
               $handling = true;
            }

            $interrupted = false;
            $selecting = true;
            $selected = stream_select($read, $write, $except, 1, 0);
            $selecting = false;

            if ($selected === false) {
               // @phpstan-ignore-next-line The error handler mutates this captured flag during stream_select().
               if ($interrupted) {
                  continue;
               }

               throw new RuntimeException('Database operation readiness wait failed.');
            }
         }

         if ($Operation->error !== null) {
            throw new RuntimeException($Operation->error);
         }

         return $Operation;
      }
      finally {
         if ($handling) {
            restore_error_handler();
         }
      }
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

      // ! The withdrawal is the caller's own decision, so it is recorded before
      //   anything else is consulted. Every route out of this method has to
      //   leave the work withdrawn — a parked refusal, a driver that refuses, a
      //   driver that throws instead of answering — or a later advance() reaches
      //   fallback() and re-dispatches the very statement the caller withdrew.
      //   The drivers mark `cancelled` only when the request reached the wire,
      //   which is what the reconciliation below reads and not what this asks.
      $Operation->revoked = true;

      // ? Nothing of this operation is on the wire any more. A cancel request
      //   names a backend, not a statement, so sending one now reaches whatever
      //   has held this connection since — measured killing an unrelated query
      //   on it. Every driver's advance() opens with this same guard, for the
      //   same reason; cancel() is the entry point that lacked it.
      if ($Operation->finished) {
         // ? It can still be parked — a pool that refused it capacity finishes
         //   it without shifting it — and `pending` carries live work only.
         $this->forget($Operation);

         return $Operation;
      }

      $Protocol = $Operation->Protocol;

      if ($Protocol === null) {
         // ? A parked operation has no protocol, and failing it while the pool
         //   still holds it in `pending` leaves promote() free to shift it once
         //   capacity frees. `pending` carries live operations only, which is
         //   what wait() and assign() both maintain.
         $this->forget($Operation);

         return $Operation->fail('Database operation has no protocol to cancel.');
      }

      // ? Composed but never written, so the server has never heard of it and
      //   there is nothing out there to cancel. A request would once more land
      //   on what the backend is actually doing — inside a transaction, on the
      //   transaction itself, which then can never be committed. It is withdrawn
      //   locally instead, the way a parked operation is. No drain() follows:
      //   the drivers leave `Queued` for `Querying` before anything claims the
      //   write stream, so abandoning one tears no session down and hands no
      //   sibling back — unlike the two branches below.
      if ($Operation->state === OperationStates::Queued) {
         $Operation->fail('Database operation was cancelled before reaching the server.');

         $Protocol->abandon($Operation);
         $this->forget($Operation);

         // ? A teardown that never reached the server ended nothing: the
         //   transaction is still open on this connection and still holds its
         //   locks, so its reservation stands. release() honours `unlock`
         //   whatever the outcome — right for a teardown the wire refused,
         //   since that session is suspect and the connection goes anyway, and
         //   wrong here. Releasing it lent an open transaction to the next
         //   caller, whose write then vanished with a session it never knew of.
         //   Skipping the release instead left the claim unsettled, so the next
         //   advance() honoured the flag after all and the branch that drops a
         //   dead connection never ran — while dropping the flag for good broke
         //   the one route that legitimately ends the transaction, a caller
         //   re-arming the teardown it withdrew.
         $this->settle($Operation, sent: false);

         return $Operation;
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

      // ? The cancel did reach the server, so the answer it provokes is what
      //   normally finishes this operation and frees the slot. On a connection
      //   that can no longer deliver anything, that answer never comes and
      //   nobody else settles the claim — the pool counts the slot forever.
      //   The socket is the whole test: a connection that is merely still
      //   handshaking is not lost, and an operation stuck there is the
      //   deadline's business, not this method's.
      $Connection = $Operation->Connection;

      if ($Operation->finished === false && $Connection !== null && is_resource($Connection->socket) === false) {
         $Operation->fail('Database connection was lost while cancelling the operation.');

         $Protocol->abandon($Operation);
         $this->drain($Protocol, $Operation);
         $this->forget($Operation);
         $this->release($Operation);
      }

      return $Operation;
   }

   /**
    * Settle one operation's claim, ending a transaction nobody else can.
    *
    * A teardown the pool retires before it reaches the server ended nothing:
    * the transaction is still open on that connection and still holds its
    * locks, and nobody is left to close it — `Transaction` gave up its depth
    * and its connection when it composed the statement. Releasing the
    * reservation hands that open transaction to the next caller, whose writes
    * then vanish with a session it never knew of; keeping it strands the slot
    * for the worker's life and leaves the session open anyway. Dropping the
    * connection is the one outcome that matches what the caller was told: the
    * server rolls the transaction back, and the slot comes back with it.
    */
   private function settle (Operation $Operation, bool $sent): void
   {
      $Protocol = $Operation->Protocol;
      $Connection = $Operation->Connection;

      // ? Only a teardown ends a transaction. An ordinary statement withdrawn
      //   inside one must leave it alone — the caller can still commit it.
      //   The `sent` half is defensive and, measured, changes nothing today:
      //   the deadline route abandons first, and `abandon()` drops a session
      //   with no reader left — which a reserved connection never has, since
      //   the pool refuses to co-locate onto one. It stays because it states
      //   the precondition the branch relies on, and a driver that keeps the
      //   wire instead would need it.
      // ? The session is still this driver's to sever. A stale teardown can
      //   outlive an abort while the pool rebuilds the same Connection object;
      //   calling the old driver's sever() then disconnects the replacement
      //   socket before release() reaches its existing stale-claim guard.
      if (
         $sent === false
         && $Operation->unlock
         && $Protocol !== null
         && $Connection !== null
         && $Connection->Protocol === $Protocol
      ) {
         // @ Through the driver, never around it. Dropping the transport from
         //   here left the driver holding a pipeline, a statement cache and a
         //   cancel key for a session that no longer existed — and the pool
         //   then built a second driver onto the same connection, so two of
         //   them drove one socket.
         $Protocol->sever($Operation, 'Database transaction teardown never reached the server.');

         // @ Severing fails every sibling on that session and hands them back;
         //   their release belongs to the connection they were on.
         $this->drain($Protocol, $Operation);
      }

      $this->release($Operation);
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

      // ? Already assigned here — assigning the same live operation again must
      //   not normalize its own busy connection as a stale pin, re-prepare its
      //   command or reserve a second slot. Pending operations carry no driver
      //   and still pass through so promote() can assign them normally.
      if ($Operation->Pool === $this && $Operation->Protocol !== null) {
         return $Operation;
      }

      $Operation->Pool = $this;

      // ? BEGIN is an exclusive request, not permission to steal the supplied
      //   pin from an operation already using it. Normalize only a lock-taking
      //   operation: ordinary statements inside an active transaction carry
      //   lock=false and must remain pinned to its reserved connection.
      $Pinned = $Operation->Connection;

      if ($Operation->lock && $Pinned !== null && isset($this->busy[spl_object_id($Pinned)])) {
         $Operation->Connection = null;
      }

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

      if ($FallbackPool === null || $FallbackPool === $this || $Operation->fallback || $Operation->cancelled || $Operation->revoked || $Operation->state !== OperationStates::Failed) {
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
