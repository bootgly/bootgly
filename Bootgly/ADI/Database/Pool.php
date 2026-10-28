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
use function array_key_last;
use function array_shift;
use function count;
use function is_callable;
use function is_resource;
use function max;
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
   // @ Slots still held by withdrawn statements the server may be running:
   //   their session was dropped — at the withdrawal, or later while it was
   //   still draining them (see `lingering`) — but the server finishes what
   //   it was asked before it notices. Each counts against `max` until the
   //   statement's own deadline. Keyed by append order, never by the
   //   operation: an object id is recycled once the withdrawn operation is
   //   freed, and a recycled key would overwrite or erase a live entry.
   /** @var array<int,float> */
   private array $quarantine = [];
   // @ Deadlines of withdrawn statements whose answers a surviving session
   //   still owes to its stand-ins, per connection id. While that session
   //   lives it holds their slot itself; if it goes before the stand-ins are
   //   drained — the next operation on it expires, an I/O error, a teardown
   //   sever — drop() turns a deadline still ahead into a quarantine entry, so
   //   a later sever never opens sessions past `max` while the server still
   //   runs them. release() forgets it once the session is drained: the server
   //   answered them all. The id stays valid while the entry does: the pool
   //   references a counted connection until drop() clears it.
   /** @var array<int,float> */
   private array $lingering = [];
   // @ Operations handed each connection, per connection id, until their
   //   claim settles in release(). A driver only sees what reached its
   //   pipeline: an operation assigned but not yet flushed — still Queued, or
   //   parked behind another caller's write — is invisible to `check()`, and an
   //   exclusive operation handed that connection would carry its statement
   //   behind the next caller's BEGIN, inside their transaction. Consulted
   //   when an exclusive operation acquires, never to hold a connection out of
   //   `idle`: a claimant its caller dropped must not pin the connection.
   //   Weak, so a dropped claimant leaves no entry behind.
   /** @var array<int,WeakMap<Operation,true>> */
   private array $claims = [];
   // @ Kept sessions whose owner was orphaned — a withdrawn statement that
   //   reached the server, an adopted transaction — reset once before they
   //   are lent again (see taint()).
   /** @var array<int,true> */
   private array $tainted = [];
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
         $this->retire($Operation, $sent);

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

            // ! Bounded by the readiness deadline, never by a fixed second: a
            //   driver that armed a wake-up before the operation's own
            //   deadline — the Redis handshake budget — is re-entered when it
            //   asked to be. A one-second floor re-entered advance() no earlier
            //   than that, so every `timeout <= 1.0` against a silent peer
            //   expired before the budget branch could run. Capped at one
            //   second so a signal or a stalled peer never parks the worker
            //   longer, and clamped at zero once the deadline has passed.
            $seconds = 1;
            $microseconds = 0;
            $deadline = $Readiness->deadline;
            if ($deadline > 0.0) {
               $remaining = $deadline - microtime(true);

               if ($remaining < 1.0) {
                  $seconds = 0;
                  $microseconds = $remaining > 0.0 ? (int) ($remaining * 1_000_000) : 0;
               }
            }

            $interrupted = false;
            $selecting = true;
            $selected = stream_select($read, $write, $except, $seconds, $microseconds);
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
      //   same route — reconcile the wire, then settle the claim.
      if ($Operation->finished && $Operation->cancelled === false) {
         $Protocol->abandon($Operation);

         // @ Same reason as the expire branch: a teardown here hands the
         //   siblings back, and their release belongs to this connection.
         $this->drain($Protocol, $Operation);

         $this->forget($Operation);
         $this->settle($Operation, sent: true);
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
    * Withdraw one unfinished operation locally, because its caller stopped
    * waiting for it — a deferred response whose wait was refused, interrupted
    * or whose Fiber was destroyed.
    *
    * Nothing is sent to the server: the operation fails, the driver reconciles
    * the wire, and the pool takes the slot back (a connection still connecting
    * or authenticating is discarded). A statement whose batch is whole on the
    * wire keeps its session (`Driver::detach()`): its remaining answer is
    * drained by the next operation handed the connection, which returns to
    * `idle` at once unless other callers still read there, and the session
    * is reset before it is lent again (`taint()`). A connection reserved by a
    * transaction is reconciled the pool's own way (`Driver::abandon()`). The
    * operation is marked `revoked`, so no fallback pool ever re-dispatches
    * it. An already finished operation keeps its result and is only
    * forgotten.
    *
    * Synchronous and never suspends: it runs inside the `finally` of Fibers
    * being destroyed, where a suspension is a `FiberError`. Freeing a slot may
    * still promote a parked operation, which can dial a new connection.
    */
   public function withdraw (Operation $Operation): Operation
   {
      $Pool = $Operation->Pool;

      if ($Pool !== null && $Pool !== $this) {
         return $Pool->withdraw($Operation);
      }

      // ! Recorded first, as cancel() records it: whatever happens below, a
      //   later advance() must never reach fallback() and re-dispatch it.
      $Operation->revoked = true;

      // ? Whoever finished it settled its claim — only a parked entry may remain.
      if ($Operation->finished) {
         $this->forget($Operation);

         return $Operation;
      }

      // ! A teardown always counts as never sent: the session it was ending is
      //   severed instead of trusting an answer nobody will read, so an open
      //   transaction is never lent to the next caller.
      $sent = $Operation->unlock === false && $Operation->state !== OperationStates::Queued;

      // ! A statement on the wire keeps running on the server after its session
      //   drops (SQL — `Driver::LINGERING`), so its slot stays counted until
      //   its own deadline whenever the session cannot be kept — a
      //   half-written batch, a reserved connection with nobody left to read:
      //   a client's disconnect rate cannot open sessions past `max` while
      //   those statements run within it. Registered before retire(), whose
      //   release may already promote into the freed slot.
      $reading = $Operation->state === OperationStates::Reading;
      $running = $reading || $Operation->state === OperationStates::Querying;
      $Connection = $Operation->Connection;
      $Protocol = $Operation->Protocol;
      $key = null;

      if ($running && $Operation->deadline > 0.0 && $Protocol !== null && $Protocol::LINGERING) {
         $this->quarantine[] = $Operation->deadline;
         $key = array_key_last($this->quarantine);
      }

      // ! Read before fail() and retire(): release() unlocks a failed lock
      //   operation, and the reservation decides how the wire is reconciled.
      //   A transaction's reserved connection is never handed to the next
      //   caller, so nobody would drain a stand-in left there — it is
      //   reconciled the pool's own way, which drops a session with no reader.
      $locked = $Connection !== null && isset($this->locked[spl_object_id($Connection)]);

      // ! A statement that reached the server may leave its session carrying
      //   what it does once its caller is gone — a lock granted after the
      //   caller stopped waiting, a LISTEN. The session kept for it is tainted
      //   (see taint()): the release that next returns it to idle writes the
      //   reset. That release is retire()'s own when nobody else still reads
      //   there, so the reset goes out at once and the server runs it right
      //   after the withdrawn statement; with live callers pipelined on the
      //   session, it is the last of theirs, so the reset never fails one of
      //   them and never ends what one of them still runs. A session dropped
      //   instead takes the taint with it (drop()).
      if (
         $reading
         && $locked === false
         && $Connection !== null
         && $Protocol !== null
         && $Connection->Protocol === $Protocol
      ) {
         $this->taint($Connection);
      }

      $Operation->fail('Database operation was withdrawn: its caller stopped waiting.');

      $this->retire($Operation, $sent, detach: $locked === false);

      // ? The session survived — detached, or a sibling still reads on it: the
      //   connection itself still counts, so the slot is not held twice.
      if ($key !== null && $Connection !== null && $Connection->Protocol === $Protocol) {
         unset($this->quarantine[$key]);

         // ? Its answer is owed to a stand-in the session kept: the statement
         //   reached the server whole (Reading) and is still being answered. It
         //   keeps the statement's deadline, for drop() to quarantine if the
         //   session goes before that answer is drained. A statement that never
         //   reached the wire — a sibling spliced out of the queue, a batch
         //   parked behind another writer — runs nowhere and is owed nothing.
         if ($reading && $Protocol !== null && $Protocol->detached) {
            $id = spl_object_id($Connection);
            $this->lingering[$id] = max($this->lingering[$id] ?? 0.0, $Operation->deadline);
         }
      }

      return $Operation;
   }

   /**
    * Retire one failed operation: reconcile the wire, then take its claim back.
    *
    * `detach` keeps a drainable session (`Driver::detach()`) — the withdrawal
    * route; otherwise the driver abandons the operation (`Driver::abandon()`),
    * dropping a session nobody is left to drain.
    */
   private function retire (Operation $Operation, bool $sent, bool $detach = false): void
   {
      // @ The driver still owns whatever the server is sending for this
      //   operation: let it reconcile the wire before the connection is
      //   handed to anyone else and before fallback() revives the object.
      $Protocol = $Operation->Protocol;

      if ($detach) {
         $Protocol?->detach($Operation);
      }
      else {
         $Protocol?->abandon($Operation);
      }

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
    *
    * The mirror case is a BEGIN given up on after it reached the server while
    * the session survives: it may have opened a transaction nobody will end,
    * so that session is dropped too rather than unlocked and lent on.
    */
   private function settle (Operation $Operation, bool $sent): void
   {
      $Protocol = $Operation->Protocol;
      $Connection = $Operation->Connection;

      // ? Only a teardown ends a transaction. An ordinary statement withdrawn
      //   inside one must leave it alone — the caller can still commit it.
      //   The `sent` half is defensive and, measured, changes nothing today:
      //   a reserved connection is always reconciled through `abandon()` —
      //   the deadline route abandons, and `withdraw()` detaches only an
      //   unreserved connection — and `abandon()` drops a session with no
      //   reader left, which a reserved connection never has, since the pool
      //   refuses to co-locate onto one. It stays because it states the
      //   precondition the branch relies on, and a driver that keeps the wire
      //   instead would need it.
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

      // ? A BEGIN given up on after it reached the server may have opened a
      //   transaction the session still carries: the driver kept the session,
      //   with the BEGIN's answer owed to a stand-in. release() would hand the
      //   reservation back — a failed lock operation does — and lend that open
      //   transaction to the next caller, whose statements would then run, and
      //   vanish, inside it. Nothing proves the BEGIN did not run, so the
      //   session goes: the server rolls back whatever it opened.
      if (
         $sent
         && $Operation->lock
         && $Protocol !== null
         && $Connection !== null
         && $Connection->Protocol === $Protocol
         && $Protocol->detached
      ) {
         $Protocol->sever($Operation, 'Database transaction start was abandoned after reaching the server.');

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

      // ! Its claim on the connection settles here — below the guard above, so
      //   a release for a torn-down session never takes a claim from the one
      //   rebuilt on the same Connection object.
      if (isset($this->claims[$id])) {
         unset($this->claims[$id][$Operation]);
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

      // ? The session drained the stand-ins it kept for withdrawn statements:
      //   the server answered them all, so a later drop owes them no slot. A
      //   dead session keeps the entry for drop() below, which decides it.
      if ($usable && isset($this->lingering[$id]) && $Connection->Protocol?->detached === false) {
         unset($this->lingering[$id]);
      }

      // ? A pipelined reply is still owed, so the connection stays busy until
      //   whoever is reading drains the FIFO — but only while the socket can
      //   still deliver it. An unusable one owes an answer it can never bring,
      //   and everything that frees the slot lives below this gate: the
      //   reservation, the busy entry, drop() and promote(). Gating on it there
      //   left the pool counting a dead connection against `max` for good.
      //   An operation that holds a claim but has not flushed does not keep
      //   it here: its caller may never come back for it. acquire() keeps
      //   exclusive operations off the connection while that claim is live.
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

      // ? A session whose owner was orphaned — a withdrawn statement that
      //   reached the server, an adopted transaction — is reset before it is
      //   lent again, once, by the release that returns it to idle: nobody
      //   still owes work on it (`check()`), so the reset fails no caller and
      //   ends nothing a live caller still runs. Its answer is drained like a
      //   stand-in's by whoever is handed the connection next. A session that
      //   cannot take it whole goes instead — no live caller shares it any
      //   more, and drop() quarantines what it still owed a withdrawn
      //   statement.
      if (isset($this->tainted[$id])) {
         unset($this->tainted[$id]);

         $Session = $Connection->Protocol;

         if ($Session !== null && $Session->reset() === false) {
            $Session->sever($Operation, 'Database session reset could not be written.');
            $this->drain($Session, $Operation);

            unset($this->idle[$id], $this->busy[$id], $this->locked[$id]);
            $this->drop($Connection);
            $this->promote();

            return $this;
         }
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

      // ! Claimed until release() settles it, whichever way the connection
      //   came — idle, dialled, co-located or pinned.
      $id = spl_object_id($Connection);
      $Claims = $this->claims[$id] ?? null;

      if ($Claims === null) {
         /** @var WeakMap<Operation,true> $Claims */
         $Claims = new WeakMap();
         $this->claims[$id] = $Claims;
      }

      $Claims[$Operation] = true;

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
    * Mark a lent session whose owner was orphaned — `withdraw()` marks the
    * one a withdrawn statement reached the server on; an adopted transaction
    * marks its reserved one — so its session state is reset before the
    * connection is lent again.
    *
    * The next release that would return the connection to `idle` — the one
    * after which nobody owes work on it (`Driver::check()`) — has the driver
    * write its reset (`Driver::reset()`) behind everything the session still
    * owes, once; a session that cannot take it is severed and dropped
    * instead. Until then no new caller is co-located there: it would run
    * before the reset. Meant for a connection the pool has lent, busy or
    * reserved: one already idle is reset only after its next caller. A
    * connection the pool does not count is ignored, and a dropped one loses
    * its mark.
    */
   public function taint (Connection $Connection): void
   {
      $id = spl_object_id($Connection);

      if (isset($this->counted[$id])) {
         $this->tainted[$id] = true;
      }
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

      $fallback = null;

      // @@
      foreach ($this->idle as $id => $Connection) {
         // ? An exclusive operation never takes a connection somebody else was
         //   handed and has not flushed yet: that statement would run behind
         //   its BEGIN, inside its transaction. release() idles such a
         //   connection anyway — the claimant's caller may be gone — so the
         //   claim is weighed here, when it is still live or already settled.
         if ($pipelineable === false && $this->hold($Connection)) {
            continue;
         }

         // ? A session still owing the answers of withdrawn statements serves
         //   only when no clean one is idle: its next caller waits behind
         //   them for as long as the server still runs them.
         if ($Connection->Protocol?->detached === true) {
            $fallback ??= $id;

            continue;
         }

         unset($this->idle[$id]);
         $this->busy[$id] = $Connection;

         return $Connection;
      }

      // ? Only sessions still draining are idle: one of them serves before a
      //   new session is dialled. It already holds its slot, and draining it
      //   is what returns it to service — passed over, its answers wait
      //   unread, and one larger than the socket buffers blocks the server
      //   mid-send for as long as the pool has room to dial around it.
      if ($fallback !== null) {
         $Connection = $this->idle[$fallback];
         unset($this->idle[$fallback]);
         $this->busy[$fallback] = $Connection;

         return $Connection;
      }

      if ($this->tally() >= $this->max) {
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

            // ? A tainted session owes its reset to the release that idles it.
            //   A caller co-located there now would run before that reset —
            //   inside whatever the withdrawn statement left on the session —
            //   and, under steady saturation, every next one would too: the
            //   session would never drain, and the reset never go out. It
            //   takes no new caller until then; they park, and are promoted
            //   once it idles, behind its reset.
            if (isset($this->tainted[$id])) {
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
      unset($this->claims[$id]);
      unset($this->tainted[$id]);

      // ? The session goes while withdrawn statements it was draining may
      //   still run on the server: their slot stays counted until the latest
      //   of their deadlines, as if they had been quarantined when withdrawn.
      if (isset($this->lingering[$id])) {
         $deadline = $this->lingering[$id];
         unset($this->lingering[$id]);

         if ($deadline > microtime(true)) {
            $this->quarantine[] = $deadline;
         }
      }

      if ($this->created > 0) {
         $this->created--;
      }
   }

   /**
    * Check whether an unfinished operation still holds a claim on a connection:
    * handed it through this pool and not yet settled. A finished operation
    * never writes again, and one re-dispatched elsewhere no longer points here.
    */
   private function hold (Connection $Connection): bool
   {
      $Claims = $this->claims[spl_object_id($Connection)] ?? null;

      // ?
      if ($Claims === null) {
         return false;
      }

      // @@
      foreach ($Claims as $Claimant => $claimed) {
         if (
            $Claimant->finished === false
            && $Claimant->Connection === $Connection
            && $Claimant->Pool === $this
         ) {
            return true;
         }
      }

      // :
      return false;
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
    * Count the slots in use: every pool-owned connection, plus every slot still
    * quarantined for a withdrawn statement whose deadline has not passed.
    */
   private function tally (): int
   {
      if ($this->quarantine !== []) {
         $now = microtime(true);

         foreach ($this->quarantine as $key => $deadline) {
            if ($deadline <= $now) {
               unset($this->quarantine[$key]);
            }
         }
      }

      return $this->created + count($this->quarantine);
   }

   /**
    * Promote pending operations while capacity is available.
    */
   private function promote (): void
   {
      // ! One pass over what is parked now. An exclusive operation re-parks
      //   when the only idle connection is still claimed, and `idle` stays
      //   non-empty: looping on it would spin the worker until its deadline.
      $turns = count($this->pending);

      // @@
      while ($turns > 0 && $this->pending !== [] && ($this->idle !== [] || $this->tally() < $this->max)) {
         $turns--;
         $Operation = array_shift($this->pending);

         if ($Operation->expire()) {
            continue;
         }

         $this->assign($Operation);

         // ? Re-parked: assign() queued it again for a later release.
         if ($Operation->state === OperationStates::Pending) {
            continue;
         }

         $this->advance($Operation);
      }
   }
}
