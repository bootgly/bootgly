<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\KV\Drivers;


use const SOL_TCP;
use const TCP_NODELAY;
use function array_shift;
use function ctype_digit;
use function extension_loaded;
use function feof;
use function fread;
use function fwrite;
use function is_int;
use function is_resource;
use function is_scalar;
use function socket_import_stream;
use function socket_set_option;
use function substr;
use RuntimeException;
use Throwable;
use WeakMap;

use Bootgly\ABI\Data\RESP\Decoder;
use Bootgly\ABI\Data\RESP\Encoder;
use Bootgly\ACI\Events\Readiness;
use Bootgly\ACI\Events\Scheduler;
use Bootgly\ADI\Database\Config;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Database\Connection\ConnectionStates;
use Bootgly\ADI\Database\Operation as DatabaseOperation;
use Bootgly\ADI\Database\Operation\OperationStates;
use Bootgly\ADI\Database\Operation\Result;
use Bootgly\ADI\Databases\KV\Driver;
use Bootgly\ADI\Databases\KV\Operation;


/**
 * Async, event-loop Redis driver (RESP over the DBAL connection pool).
 *
 * The non-blocking counterpart to the blocking cache driver: it drives one
 * RESP command per Operation through the same `Connection`/`Pool` state machine
 * the PostgreSQL driver uses, reusing the shared `ABI\Data\RESP` codec. Use this
 * inside the async HTTP worker, where a blocking Redis call would stall the loop.
 *
 * Commands are pipelined per connection: co-located operations write their
 * frames back-to-back on the same socket and replies resolve them FIFO (Redis
 * answers in order), so N in-flight commands share round-trips. AUTH/SELECT
 * are sent once as a preamble when a connection is first opened.
 */
class Redis extends Driver
{
   // * Config
   public Encoder $Encoder;
   public Decoder $Decoder;

   // * Metadata
   // @ Number of preamble replies (AUTH/SELECT) still to discard before the command reply.
   private int $skip = 0;
   /** @var array<int,Operation> In-flight commands awaiting replies (FIFO — Redis answers in order). */
   private array $pipeline = [];
   /** @var array<int,Operation> Operations resolved while another operation was advancing. */
   private array $completed = [];
   // @ This driver tore its own transport down and the pool has moved on.
   private bool $aborted = false;
   // @ Stand-in slots owed a reply nobody waits for. Keyed by the object, not by
   //   spl_object_id: an id is recycled as soon as its stand-in is collected, and
   //   a live command landing on one reads as abandoned — which drops it out of
   //   abandon()'s reader count and kills a healthy session.
   /** @var WeakMap<Operation,true> */
   private WeakMap $abandoned;
   /** Operation currently holding the write stream (partial writes must not interleave). */
   private null|Operation $Writing = null;
   private null|Readiness $ReadReadiness = null;
   private null|Readiness $WriteReadiness = null;
   /** @var resource|null */
   private mixed $cachedSocket = null;


   public function __construct (Config $Config, Connection $Connection)
   {
      $this->abandoned = new WeakMap();

      parent::__construct($Config, $Connection);

      // * Config
      $this->Encoder = new Encoder;
      $this->Decoder = new Decoder;
   }

   /**
    * Create a Redis command operation.
    *
    * @param array<int,mixed> $arguments
    */
   public function command (string $command, array $arguments = []): Operation
   {
      $Operation = new Operation($this->Connection, $command, $arguments, $this->Config->timeout);
      $this->prepare($Operation);

      return $Operation;
   }

   /**
    * Prepare an operation for Redis execution.
    */
   public function prepare (DatabaseOperation $Operation): DatabaseOperation
   {
      if ($Operation instanceof Operation === false) {
         return $Operation->fail('Redis requires a KV operation.');
      }

      /** @var Operation $Operation */

      $Operation->Connection = $this->Connection;
      $Operation->Protocol = $this;
      $Operation->state = OperationStates::Queued;
      $Operation->write = $this->Encoder->encode($this->frame($Operation));

      return $Operation;
   }

   /**
    * Advance a Redis operation through the connection state machine.
    */
   public function advance (DatabaseOperation $Operation): DatabaseOperation
   {
      if ($Operation instanceof Operation === false) {
         return $Operation->fail('Redis requires a KV operation.');
      }

      /** @var Operation $Operation */

      // ?
      if ($Operation->finished) {
         return $Operation;
      }

      // ? This driver dropped its transport and the Connection no longer binds
      //   it, so the pool has built a fresh driver on the same Connection. An
      //   operation still pointing here — assigned before the teardown and not
      //   advanced since — would reconnect that shared Connection through this
      //   object and pipeline on it behind a FIFO the live driver cannot see:
      //   two decoders on one wire, each taking replies meant for the other.
      if ($this->aborted) {
         $Operation->quarantine = true;

         return $Operation->fail('Redis connection was torn down before the command was sent.');
      }

      if ($Operation->state === OperationStates::Queued) {
         if ($this->Connection->connected === false || is_resource($this->Connection->socket) === false) {
            $Operation->state = OperationStates::Connecting;

            try {
               return $Operation->await($this->Connection->connect($Operation->deadline));
            }
            catch (Throwable $Throwable) {
               $Operation->quarantine = true;

               return $Operation->fail($Throwable->getMessage());
            }
         }

         $Operation->state = OperationStates::Querying;
      }

      if ($Operation->state === OperationStates::Connecting) {
         // ? A co-located sibling can abort between this operation's connect()
         //   and its transition — the teardown drops the shared socket, and
         //   transition() rejects a connection without one.
         if (is_resource($this->Connection->socket) === false) {
            return $this->abort($Operation, 'Redis socket is not available.');
         }

         // @ TCP is established; mark ready and prepend the AUTH/SELECT preamble once.
         $this->Connection->transition(ConnectionStates::Ready);

         // ! Fresh wire state — a new socket must not inherit a stale partial frame
         $this->Decoder->reset();

         // ! A fresh socket orphans any commands in flight on the previous one
         foreach ($this->pipeline as $Stale) {
            if ($Stale === $Operation) {
               continue;
            }

            if ($Stale->finished === false) {
               $Stale->fail('Redis connection was lost before the reply arrived.');
            }

            $this->completed[] = $Stale;
         }
         $this->pipeline = [];

         // ! The command that held the write stream owns bytes of the socket
         //   that just died. Its buffer must not be flushed onto this one.
         $Stale = $this->Writing;
         $this->Writing = null;

         if ($Stale !== null && $Stale !== $Operation) {
            $Stale->write = '';

            if ($Stale->finished === false) {
               $Stale->fail('Redis connection was lost before the command was sent.');
               $this->completed[] = $Stale;
            }
         }

         // @ Disable Nagle: commands and replies are small — latency dominates
         $socket = $this->Connection->socket;
         if (is_resource($socket) === true && extension_loaded('sockets') === true) {
            $Raw = socket_import_stream($socket);
            if ($Raw !== false) {
               @socket_set_option($Raw, SOL_TCP, TCP_NODELAY, 1);
            }
         }

         $preamble = '';
         $this->skip = 0;
         if ($this->Config->password !== '') {
            $preamble .= $this->Encoder->encode(['AUTH', $this->Config->password]);
            $this->skip++;
         }
         // ? Database\Config->database is a name string; SELECT only a numeric, non-zero index
         $database = $this->Config->database;
         if ($database !== '' && $database !== '0' && ctype_digit($database) === true) {
            $preamble .= $this->Encoder->encode(['SELECT', $database]);
            $this->skip++;
         }

         $Operation->write = $preamble . $Operation->write;
         $Operation->state = OperationStates::Querying;
      }

      if ($Operation->state === OperationStates::Querying) {
         // ? A co-located sibling holds the write stream — wait so the
         //   pipelined commands are not interleaved on the socket.
         if ($this->Writing !== null && $this->Writing !== $Operation && $this->Writing->finished === false) {
            return $this->await($Operation, Scheduler::SCHEDULE_WRITE);
         }

         $this->Writing = $Operation;

         if ($this->flush($Operation) === false) {
            // @ A partial write keeps the stream held; on a hard failure the
            //   operation is finished and the guard above treats it as free.
            return $Operation;
         }

         $this->Writing = null;

         $Operation->state = OperationStates::Reading;
         $this->queue($Operation);

         return $this->await($Operation, Scheduler::SCHEDULE_READ);
      }

      if ($Operation->state === OperationStates::Reading) {
         $this->read($Operation);

         if ($Operation->finished) {
            return $Operation;
         }

         return $this->await($Operation, Scheduler::SCHEDULE_READ);
      }

      return $Operation;
   }

   /**
    * Reconcile the wire when the pool takes one operation away.
    */
   public function abandon (DatabaseOperation $Operation): void
   {
      $index = null;

      foreach ($this->pipeline as $id => $Queued) {
         if ($Queued === $Operation) {
            $index = $id;

            break;
         }
      }

      // ? Not pipelined here — a command joins the FIFO only once its frame is
      //   whole on the wire, so nothing is owed to this one.
      if ($index === null) {
         // ? Unless it holds the write stream: a half-written frame leaves the
         //   server reading a bulk string that never ends, so the next command
         //   handed this connection is consumed as the payload of this one.
         if ($this->Writing === $Operation) {
            $this->abort($Operation, 'Redis operation was abandoned while writing its command.');
         }

         return;
      }

      /** @var Operation $Operation */

      // ? Draining needs a reader: the pool has already released this operation,
      //   so only a sibling advance still pumps this socket.
      $readers = 0;

      foreach ($this->pipeline as $Queued) {
         if ($Queued !== $Operation && isset($this->abandoned[$Queued]) === false) {
            $readers++;
         }
      }

      // @ The command still being written is a reader too — it is the operation
      //   its caller is actively advancing, and it joins the FIFO behind this
      //   slot as soon as its bytes are whole on the wire.
      $Writing = $this->Writing;

      if ($Writing !== null && $Writing !== $Operation && $Writing->finished === false) {
         $readers++;
      }

      if ($readers === 0) {
         $this->abort($Operation, 'Redis abandoned command has no reader left to drain its reply.');

         return;
      }

      // @ Detach the operation from the slot it still owns. Redis answers in
      //   order, so the slot cannot simply be dropped — the reply is applied to
      //   a stand-in nobody waits for, and the object the pool took back is
      //   never resolved by this driver again.
      $Stand = new Operation(null, $Operation->command, $Operation->arguments);
      $Stand->state = OperationStates::Reading;

      $this->pipeline[$index] = $Stand;
      $this->abandoned[$Stand] = true;
   }

   /**
    * Check whether this connection still has pipelined commands in flight.
    */
   public function check (): bool
   {
      // @@ A finished entry is not work in flight: the read loop keeps it only
      //    until the message that terminates it arrives, and it stays at its
      //    slot to absorb that message whoever reads next. Reading a non-empty
      //    FIFO as "still busy" gates release() off for the connection's life
      //    when the command that failed was answered in two reads.
      foreach ($this->pipeline as $Queued) {
         if ($Queued->finished === false) {
            return true;
         }
      }

      // :
      return false;
   }

   /**
    * Drain operations completed while reading pipelined replies.
    *
    * @return array<int,Operation>
    */
   public function drain (): array
   {
      $Completed = $this->completed;
      $this->completed = [];

      return $Completed;
   }

   // ---

   /**
    * Build the command frame (verb + arguments) for the RESP encoder.
    *
    * @return array<int,int|string>
    */
   private function frame (Operation $Operation): array
   {
      $frame = [$Operation->command];

      foreach ($Operation->arguments as $argument) {
         if (is_int($argument) === true) {
            $frame[] = $argument;
         }
         else {
            $frame[] = is_scalar($argument) === true ? (string) $argument : '';
         }
      }

      return $frame;
   }

   /**
    * Flush the operation write buffer to the socket.
    */
   private function flush (Operation $Operation): bool
   {
      if ($Operation->write === '') {
         return true;
      }

      $socket = $this->Connection->socket;
      if (is_resource($socket) === false) {
         $this->abort($Operation, 'Redis socket is not available.');

         return false;
      }

      $written = @fwrite($socket, $Operation->write);

      if ($written === false) {
         $this->abort($Operation, 'Redis socket write failed.');

         return false;
      }

      if ($written === 0) {
         if (feof($socket)) {
            $this->abort($Operation, 'Redis socket closed during write.');

            return false;
         }

         $this->await($Operation, Scheduler::SCHEDULE_WRITE);

         return false;
      }

      $Operation->write = substr($Operation->write, $written);

      if ($Operation->write !== '') {
         $this->await($Operation, Scheduler::SCHEDULE_WRITE);

         return false;
      }

      return true;
   }

   /**
    * Read available bytes and resolve the operation once its reply arrives.
    */
   private function read (Operation $Operation): void
   {
      $socket = $this->Connection->socket;
      if (is_resource($socket) === false) {
         $this->abort($Operation, 'Redis socket is not available.');

         return;
      }

      $bytes = @fread($socket, 16384);

      if ($bytes === false) {
         $this->abort($Operation, 'Redis socket read failed.');

         return;
      }

      if ($bytes === '') {
         if (feof($socket)) {
            $this->abort($Operation, 'Redis socket closed.');
         }

         return;
      }

      try {
         $replies = $this->Decoder->decode($bytes);
      }
      catch (Throwable $Throwable) {
         // @ A malformed frame desynchronises the RESP stream: every later reply
         //   would be attributed to the wrong command, and nothing can resync it.
         $this->abort($Operation, $Throwable->getMessage());

         return;
      }

      foreach ($replies as $reply) {
         // @ Discard AUTH/SELECT preamble replies first
         if ($this->skip > 0) {
            $this->skip--;

            if ($reply instanceof RuntimeException) {
               // @ AUTH or SELECT was refused, so the session is unusable — and
               //   the replies this loop has already consumed cannot be put back.
               //   Returning here left the FIFO shifted by one for the rest of
               //   the connection's life, resolving every later command with its
               //   predecessor's reply: a GET answered with another key's value,
               //   reported as success.
               $this->abort($Operation, $reply->getMessage());

               return;
            }

            continue;
         }

         // @ Replies resolve in-flight commands FIFO (Redis answers in order)
         $Active = $this->pipeline[0] ?? null;
         if ($Active !== null) {
            array_shift($this->pipeline);
         }
         elseif ($Operation->finished === false) {
            // ? Safety net: an unqueued operation owns the reply
            $Active = $Operation;
         }
         else {
            // ? Stray reply with no in-flight owner
            continue;
         }

         // ? Expired/failed command: its reply slot is consumed, the response discarded
         if ($Active->finished) {
            continue;
         }

         if ($reply instanceof RuntimeException) {
            $Active->fail($reply->getMessage());
         }
         else {
            $Active->response = $reply;
            $Active->resolve(new Result($Active->command));
         }

         // ? A stand-in is not an operation the pool ever assigned: draining it
         //   must not hand a connection back a second time.
         if (isset($this->abandoned[$Active])) {
            continue;
         }

         $this->completed[] = $Active;
      }
   }

   /**
    * Queue one operation as in-flight for ordered replies.
    */
   private function queue (Operation $Operation): void
   {
      foreach ($this->pipeline as $Queued) {
         if ($Queued === $Operation) {
            return;
         }
      }

      $this->pipeline[] = $Operation;
   }

   /**
    * Attach event-loop readiness for the next I/O step.
    */
   private function await (Operation $Operation, int $flag): Operation
   {
      $socket = $this->Connection->socket;

      if (is_resource($socket) === false) {
         return $this->abort($Operation, 'Redis socket is not available.');
      }

      // @ Invalidate cached Readiness when the socket changes.
      if ($this->cachedSocket !== $socket) {
         $this->cachedSocket = $socket;
         $this->ReadReadiness = null;
         $this->WriteReadiness = null;
      }

      if ($flag === Scheduler::SCHEDULE_WRITE) {
         $Readiness = $this->WriteReadiness
            ?? ($this->WriteReadiness = Readiness::write($socket, $Operation->deadline));
      }
      else {
         $Readiness = $this->ReadReadiness
            ?? ($this->ReadReadiness = Readiness::read($socket, $Operation->deadline));
      }

      $Readiness->renew($Operation->deadline);
      $Operation->await($Readiness);

      return $Operation;
   }

   /**
    * Tear the session down after an unrecoverable transport failure.
    */
   private function abort (Operation $Operation, string $error): Operation
   {
      // ! Wire state — a partial frame and a preamble count belong to the dead
      //   socket, and the next connection must not inherit either.
      $this->Decoder->reset();
      $this->skip = 0;
      $this->abandoned = new WeakMap();

      // ! So does the half-written command. A command joins the FIFO only once
      //   its frame is whole, so this one is nowhere else — and nulling the
      //   stream pointer without discarding its buffer left it holding the tail
      //   of a frame. Its next advance flushed those bytes onto the NEW socket,
      //   where Redis reads a truncated value as inline commands and runs them.
      $Writer = $this->Writing;
      $this->Writing = null;

      if ($Writer !== null) {
         $Writer->write = '';

         if ($Writer->finished === false) {
            $Writer->quarantine = true;
            $Writer->fail($error);

            if ($Writer !== $Operation) {
               $this->completed[] = $Writer;
            }
         }
      }

      $Pipeline = $this->pipeline;
      $this->pipeline = [];

      // @@ Pipelined commands — completed[] hands the siblings to Pool::drain()
      foreach ($Pipeline as $Queued) {
         if ($Queued->finished === false) {
            $Queued->quarantine = true;
            $Queued->fail($error);
         }

         if ($Queued !== $Operation) {
            $this->completed[] = $Queued;
         }
      }

      if ($Operation->finished === false) {
         $Operation->quarantine = true;
         $Operation->fail($error);
      }

      // @ Drop the transport. Only the peer closed, so the socket still reports
      //   `connected`, `Ready` and `is_resource()` — without this the pool keeps
      //   the connection in `busy` behind its check() gate for the worker's life,
      //   and advance()'s reconnect branch, the only code that clears the
      //   pipeline, is never reachable again.
      $this->Connection->disconnect();
      $this->aborted = true;

      // :
      return $Operation;
   }
}
