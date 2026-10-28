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


use Bootgly\ADI\Database\Config;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Database\Operation;


/**
 * Database wire driver base.
 *
 * Concrete drivers own protocol-specific encoding, decoding and operation
 * progression while Database keeps a single transport-agnostic core.
 */
abstract class Driver
{
   /**
    * Whether a statement already sent keeps running on the server after its
    * session drops. SQL servers finish (or roll back) what they were asked
    * before they notice the client left, so once a withdrawn statement's
    * session drops — at the withdrawal or any time later — the pool keeps its
    * slot counted until the statement's deadline (see `Pool::withdraw()`).
    */
   public const bool LINGERING = true;

   // * Config
   public Config $Config;
   public Connection $Connection;

   // * Data
   // ...

   // * Metadata
   /**
    * Whether this session still owes answers to detached stand-ins — the
    * remaining answers of withdrawn or abandoned operations, left for the
    * next reader to drain. The pool keeps a withdrawn statement's deadline
    * only while this holds: once drained, the server has answered it, and a
    * later drop of the session owes it nothing. Drivers that never detach
    * keep it false.
    */
   public bool $detached {
      get => false;
   }


   public function __construct (Config $Config, Connection $Connection)
   {
      // * Config
      $this->Config = $Config;
      $this->Connection = $Connection;
   }

   /**
    * Prepare an existing operation for this driver.
    */
   abstract public function prepare (Operation $Operation): Operation;

   /**
    * Advance a pending operation through the driver state machine.
    */
   abstract public function advance (Operation $Operation): Operation;

   /**
    * Cancel one running operation when the concrete driver supports it.
    */
   public function cancel (Operation $Operation): Operation
   {
      return $Operation->fail('Database driver does not support cancellation.');
   }

   /**
    * Reconcile the wire when the pool abandons one operation.
    *
    * The pool finishes operations from the outside — an elapsed deadline, a
    * cancel that never reached the server, a transaction teardown — while the
    * server may still be answering them, and a fallback retry may start
    * running the very same object on another connection. A driver that holds
    * wire state for the abandoned operation reconciles it here: it either
    * keeps owning the wire until the response has been drained, or drops the
    * session when the remaining bytes can no longer be attributed — or when
    * no sibling is left to pump them. Drivers that keep no such state do
    * nothing.
    */
   public function abandon (Operation $Operation): void
   {
   }

   /**
    * Reconcile the wire for one operation whose caller stopped waiting,
    * always keeping a drainable session when its batch is whole on the wire.
    *
    * Unlike `abandon()`, no reader is required: the remaining answer is left
    * to a detached stand-in, `check()` stops counting the connection as busy
    * for it, and the next operation the pool hands that connection queues
    * behind the stand-in and drains it. The live session then holds its slot
    * for the statement's real runtime, not for its deadline. A batch whose
    * remaining bytes can never be attributed — half written — still drops
    * the session.
    *
    * The default delegates to `abandon()`, for drivers that keep no drainable
    * wire state. A driver whose `abandon()` calls `detach()` must override
    * this method, or the two recurse.
    */
   public function detach (Operation $Operation): void
   {
      $this->abandon($Operation);
   }

   /**
    * Reset what a kept session may carry once its caller is gone.
    *
    * A session the pool keeps after a withdrawal — or after its owner was
    * orphaned another way — may still hold state its caller left behind: a
    * lock granted after the caller stopped waiting, a subscription. The pool
    * asks for the reset as it returns that session to idle, once nobody owes
    * work on it (`check()`): the driver writes it at once, behind everything
    * the session still owes, so the server runs it right after the abandoned
    * work ends even if nobody reads, and its answer is absorbed like a
    * stand-in's by whoever reads next. A reset still owed with nothing
    * written behind it covers a repeated request. Returns false when the
    * reset cannot be written whole now — a batch holds the write stream, the
    * stream takes less than the whole reset, the session is gone — and the
    * caller then severs the session, which no caller shares any more.
    * Drivers that keep no such state have nothing to reset.
    */
   public function reset (): bool
   {
      return true;
   }

   /**
    * Sever this session because the pool cannot leave it as it is.
    *
    * The pool reaches this when a statement it retired left the server in a
    * state nobody can resolve — a transaction teardown that never arrived, so
    * the transaction stays open with nobody left able to end it. The connection
    * has to go, and only the driver knows what dies with it: pipelined siblings
    * have to be failed and handed back through `drain()`, and whatever the
    * session cached server-side must not outlive the socket. Dropping the
    * transport from outside leaves a driver still holding a pipeline, a
    * statement cache and a cancel key for a session that no longer exists.
    *
    * What this does NOT reach, because the drivers' teardown knows only the
    * pipeline and the write holder: an operation composed on the session but
    * never written, and — in PostgreSQL — the one holding a half-flushed
    * batch, which is dropped rather than failed. Either is left pointing at a
    * driver whose session is gone.
    */
   public function sever (Operation $Operation, string $error): void
   {
      if ($Operation->finished === false) {
         $Operation->fail($error);
      }

      $this->Connection->disconnect();
   }

   /**
    * Check whether this driver still owes work to a caller, so the pool must
    * not lend its connection yet: a batch another caller is still flushing,
    * or an in-flight operation somebody waits for. Detached stand-ins that
    * only drain abandoned answers do not count — the next operation handed
    * the connection queues behind them and pumps them.
    */
   public function check (): bool
   {
      return false;
   }

   /**
    * Drain operations completed internally by this driver.
    *
    * @return array<int,Operation>
    */
   public function drain (): array
   {
      return [];
   }
}
