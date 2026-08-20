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
   // * Config
   public Config $Config;
   public Connection $Connection;

   // * Data
   // ...

   // * Metadata
   // ...


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
    * The pool finishes operations from the outside — an elapsed deadline —
    * while the server may still be answering them, and a fallback retry may
    * start running the very same object on another connection. A driver that
    * holds wire state for the abandoned operation reconciles it here: it
    * either keeps owning the wire until the response has been drained, or
    * drops the session when the remaining bytes can no longer be attributed.
    * Drivers that keep no such state do nothing.
    */
   public function abandon (Operation $Operation): void
   {
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
    * Check whether this driver still has in-flight operations.
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
