<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
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
   /** Operation currently holding the write stream (partial writes must not interleave). */
   private null|Operation $Writing = null;
   private null|Readiness $ReadReadiness = null;
   private null|Readiness $WriteReadiness = null;
   /** @var resource|null */
   private mixed $cachedSocket = null;


   public function __construct (Config $Config, Connection $Connection)
   {
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
         $this->Writing = null;

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
    * Check whether this connection still has pipelined commands in flight.
    */
   public function check (): bool
   {
      return $this->pipeline !== [];
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
         $Operation->quarantine = $this->Connection->state !== ConnectionStates::Ready;
         $Operation->fail('Redis socket is not available.');

         return false;
      }

      $written = @fwrite($socket, $Operation->write);

      if ($written === false) {
         $Operation->quarantine = $this->Connection->state !== ConnectionStates::Ready;
         $Operation->fail('Redis socket write failed.');

         return false;
      }

      if ($written === 0) {
         if (feof($socket)) {
            $Operation->quarantine = $this->Connection->state !== ConnectionStates::Ready;
            $Operation->fail('Redis socket closed during write.');

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
         $Operation->quarantine = $this->Connection->state !== ConnectionStates::Ready;
         $Operation->fail('Redis socket is not available.');

         return;
      }

      $bytes = @fread($socket, 16384);

      if ($bytes === false) {
         $Operation->quarantine = $this->Connection->state !== ConnectionStates::Ready;
         $Operation->fail('Redis socket read failed.');

         return;
      }

      if ($bytes === '') {
         if (feof($socket)) {
            $Operation->quarantine = $this->Connection->state !== ConnectionStates::Ready;
            $Operation->fail('Redis socket closed.');
         }

         return;
      }

      try {
         $replies = $this->Decoder->decode($bytes);
      }
      catch (Throwable $Throwable) {
         $Operation->fail($Throwable->getMessage());

         return;
      }

      foreach ($replies as $reply) {
         // @ Discard AUTH/SELECT preamble replies first
         if ($this->skip > 0) {
            $this->skip--;

            if ($reply instanceof RuntimeException) {
               $Operation->fail($reply->getMessage());

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
         $Operation->quarantine = $this->Connection->state !== ConnectionStates::Ready;
         $Operation->fail('Redis socket is not available.');

         return $Operation;
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
}
