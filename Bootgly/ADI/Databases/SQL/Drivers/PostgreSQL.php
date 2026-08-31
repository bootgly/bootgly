<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL\Drivers;


use function array_key_first;
use function array_shift;
use function count;
use function explode;
use function fclose;
use function feof;
use function fread;
use function fwrite;
use function hex2bin;
use function implode;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_numeric;
use function is_resource;
use function is_scalar;
use function is_string;
use function ltrim;
use function microtime;
use function preg_match;
use function sha1;
use function spl_object_id;
use function str_starts_with;
use function stream_get_meta_data;
use function stream_socket_client;
use function strlen;
use function strtolower;
use function substr;
use DateTimeImmutable;
use Throwable;
use WeakReference;

use Bootgly\ABI\Events\Emitter;
use Bootgly\ACI\Events\Readiness;
use Bootgly\ACI\Events\Scheduler;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Database\Connection\ConnectionStates;
use Bootgly\ADI\Database\Operation as DatabaseOperation;
use Bootgly\ADI\Database\Operation\OperationStates;
use Bootgly\ADI\Database\Operation\Result;
use Bootgly\ADI\Databases\SQL\Config;
use Bootgly\ADI\Databases\SQL\Driver;
use Bootgly\ADI\Databases\SQL\Drivers\PostgreSQL\Authentication;
use Bootgly\ADI\Databases\SQL\Drivers\PostgreSQL\Decoder;
use Bootgly\ADI\Databases\SQL\Drivers\PostgreSQL\Encoder;
use Bootgly\ADI\Databases\SQL\Drivers\PostgreSQL\Message;
use Bootgly\ADI\Databases\SQL\Events;
use Bootgly\ADI\Databases\SQL\Operation;


/**
 * PostgreSQL Protocol 3.0 implementation.
 */
class PostgreSQL extends Driver
{
   // * Config
   public Authentication $Authentication;
   public Encoder $Encoder;
   public Decoder $Decoder;

   // * Data
   /** @var array<string,bool|array<int,int>> */
   public private(set) array $statements = [];
   public private(set) int $backendProcess = 0;
   public private(set) int $backendSecret = 0;
   /** @var array<string,string> */
   public private(set) array $parameters = [];
   /** @var array<int,array<string,mixed>> */
   public private(set) array $notices = [];
   /** @var array<int,array<string,mixed>> */
   public private(set) array $notifications = [];

   // * Metadata
   /** @var array<int,Operation> */
   private array $pipeline = [];
   /** @var array<int,Operation> */
   private array $completed = [];
   // @ True when the current stream completed TLS here or arrived encrypted.
   private bool $encrypted = false;
   // @ This driver tore its session down and must never drive a replacement
   //   socket attached to the same Connection object.
   private bool $aborted = false;
   // @ Operation currently holding the socket write stream (co-located pipelining).
   private null|Operation $writing = null;
   // @ Holder bytes accepted by the stream. A positive count makes withdrawal
   //   unsafe on every transport. Zero proves no reach only on plaintext: TLS
   //   may retain an uncredited record that requires this exact buffer again.
   private int $wrote = 0;
   // @ Statement Closes riding the holder's batch — requeued if that batch is
   //   withdrawn before any byte reaches the wire, or the backend keeps names
   //   the driver believes closed.
   /** @var array<int,string> */
   private array $carrying = [];
   /** @var array<string,string> */
   private array $names = [];
   // @ Result layout per statement name, learned from the RowDescription its
   //   Parse-time Describe returned. A warm batch applies it and omits its own
   //   portal Describe, so the backend stops repeating the layout on every
   //   execute. Evicted with the statement it describes: a name the backend no
   //   longer has must not leave a layout behind for a later re-Parse.
   /** @var array<string,array{columns:array<int,string>,types:array<int,int>}> */
   private array $layouts = [];
   // @ Statement names with a Parse in flight. The value is a weak reference to
   //   the operation that composed it while its batch is still unsent, and
   //   `true` once those bytes have reached the socket — a sibling may Bind the
   //   name warm only after that, see prepare(). Weak because a composer whose
   //   caller dropped it must not be kept alive here: the ledger would then pin
   //   its whole result set for the connection's life.
   /** @var array<string,true|WeakReference<Operation>> */
   private array $preparing = [];
   // @ Statement names evicted client-side, awaiting their paired wire Close.
   /** @var array<string,true> */
   private array $closing = [];
   // @ Operations that composed a warm Bind of a statement they do not Parse,
   //   per statement name — a queued Close waits for their bytes.
   /** @var array<string,array<int,Operation>> */
   private array $Holders = [];
   // @ Detached stand-ins draining the answers of abandoned operations.
   /** @var array<int,true> */
   private array $abandoned = [];
   private null|Readiness $ReadReadiness = null;
   private null|Readiness $WriteReadiness = null;
   /** @var resource|null */
   private mixed $cachedSocket = null;


   public function __construct (Config $Config, Connection $Connection)
   {
      parent::__construct($Config, $Connection);

      // * Config
      $this->Authentication = new Authentication($Config);
      $this->Encoder = new Encoder;
      $this->Decoder = new Decoder;
   }

   /**
    * Create a PostgreSQL simple-query operation.
    *
    * @param array<int|string,mixed> $parameters
    */
   public function query (string $sql, array $parameters = []): Operation
   {
      $Operation = new Operation($this->Connection, $sql, $parameters, $this->Config->timeout);
      $this->prepare($Operation);

      return $Operation;
   }

   /**
    * Prepare an operation for PostgreSQL execution.
    */
   public function prepare (DatabaseOperation $Operation): DatabaseOperation
   {
      if ($Operation instanceof Operation === false) {
         return $Operation->fail('PostgreSQL requires an SQL operation.');
      }

      /** @var Operation $Operation */

      $Operation->Connection = $this->Connection;
      $Operation->Protocol = $this;
      $Operation->state = OperationStates::Queued;

      try {
         $Encoder = $this->Encoder;

         if ($Operation->parameters === []) {
            $Operation->write = $Encoder->query($Operation->SQL);

            return $Operation;
         }

         $types = [];
         $index = 0;

         foreach ($Operation->parameters as $parameter) {
            $types[] = $this->infer($parameter, $Operation, $index);
            $index++;
         }

         // ! Inferred types join the statement identity — the same SQL with a
         //   different type signature must Parse as a different statement.
         $signature = implode(',', $types);
         $key = "{$Operation->SQL}\0{$signature}";
         $statement = $this->names[$key] ?? '';

         if ($statement === '') {
            $hash = sha1($key);
            $statement = "bootgly_{$hash}";
            $this->names[$key] = $statement;
         }

         $Operation->statement = $statement;
         $Operation->portal = '';
         $cached = $this->statements[$Operation->statement] ?? false;
         // ! An operation must never read its OWN in-flight marker. prepare()
         //   runs twice for one operation on a cold connection — once from
         //   Pool::assign(), then again after the handshake overwrote the
         //   composed batch with the startup packet — and on that second pass
         //   it has to compose the Parse again, not Bind a name the backend
         //   never registered.
         $marker = $this->preparing[$Operation->statement] ?? null;

         if ($marker instanceof WeakReference) {
            $Composer = $marker->get();

            // ? Collected, this operation's own from an earlier pass, or one its
            //   caller gave up on: either way those bytes never left, so the name
            //   is free and this batch Parses it. `fail()` is invisible to the
            //   driver, and a marker nothing releases suppresses every later
            //   Parse for that name.
            if ($Composer === null || $Composer === $Operation || $Composer->finished) {
               unset($this->preparing[$Operation->statement]);

               $marker = null;
            }
         }

         $Operation->prepared = $cached !== false || $marker !== null;

         $bind = $Encoder->bind([
            'portal' => $Operation->portal,
            'statement' => $Operation->statement,
            'parameters' => $Operation->parameters,
            'types' => is_array($cached) ? $cached : [],
         ]);
         $describe = $Encoder->describe($Operation->portal);
         $execute = $Encoder->execute($Operation->portal);
         $sync = Encoder::SYNC_BYTES;

         if ($Operation->prepared) {
            $layout = null;

            if ($cached !== false) {
               // ! LRU touch — reinsert inline: evict() queues a wire Close,
               //   which a touch must never do.
               unset($this->statements[$Operation->statement]);
               $this->statements[$Operation->statement] = $cached;

               // ? Only a settled cache entry may carry a layout. An operation
               //   binding against a Parse still in flight ($marker) has none,
               //   so it keeps asking the backend for the RowDescription.
               $layout = $this->layouts[$Operation->statement] ?? null;
            }

            // ?: Layout known — drop the portal Describe from the batch and the
            //    RowDescription from the answer, one backend message per query.
            if ($layout !== null) {
               $Operation->columns = $layout['columns'];
               $Operation->types = $layout['types'];
               $Operation->write = "{$bind}{$execute}{$sync}";
            }
            else {
               $Operation->write = "{$bind}{$describe}{$execute}{$sync}";
            }

            // ! This batch Binds a statement it does not Parse — hold the name
            //   against any queued Close until these bytes reach the socket.
            $this->Holders[$Operation->statement][spl_object_id($Operation)] = $Operation;

            return $Operation;
         }

         if ($this->SQLConfig->statements > 0 && count($this->statements) >= $this->SQLConfig->statements) {
            $evicted = array_key_first($this->statements);

            // ! The paired Close rides the driver-level buffer and is rendered
            //   ahead of the next flushed batch.
            $this->evict($evicted);
         }

         $parse = $Encoder->parse([
            'statement' => $Operation->statement,
            'sql' => $Operation->SQL,
            'types' => $types,
         ]);
         $describeStatement = $Encoder->describe([
            'type' => 'S',
            'name' => $Operation->statement,
         ]);
         $Operation->write = "{$parse}{$describeStatement}{$bind}{$describe}{$execute}{$sync}";

         // ! Mark the name in flight here, where the Parse is composed — not
         //   after the flush. A sibling composed inside that gap would read an
         //   empty ledger and Parse the same name again, and the backend
         //   answers the second one with 42P05 instead of running the query.
         $this->preparing[$Operation->statement] = WeakReference::create($Operation);

         return $Operation;
      }
      catch (Throwable $Throwable) {
         return $Operation->fail($Throwable->getMessage());
      }
   }

   /**
    * Advance a PostgreSQL operation.
    */
   public function advance (DatabaseOperation $Operation): DatabaseOperation
   {
      if ($Operation instanceof Operation === false) {
         return $Operation->fail('PostgreSQL requires an SQL operation.');
      }

      /** @var Operation $Operation */

      // ?
      if ($Operation->finished) {
         return $Operation;
      }

      if ($this->aborted) {
         $Operation->quarantine = true;

         return $Operation->fail('PostgreSQL connection was torn down before the query was sent.');
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

         $metadata = stream_get_meta_data($this->Connection->socket);
         $this->encrypted = is_array($metadata['crypto'] ?? null);
         $Operation->state = OperationStates::Querying;
      }

      if ($Operation->state === OperationStates::Connecting) {
         $this->encrypted = false;
         $mode = $this->Config->secure['mode'];

         if ($mode === Config::SECURE_DISABLE) {
            $this->Connection->transition(ConnectionStates::Startup);
            $this->Authentication->authenticated = false;
            $Operation->write = $this->Encoder->encode(Encoder::STARTUP, $this->Config);
            $Operation->state = OperationStates::Startup;
         }
         else {
            $this->Connection->transition(ConnectionStates::SSLRequest);
            $Operation->write = $this->Encoder->encode(Encoder::SSL);
            $Operation->state = OperationStates::SSLRequest;
         }
      }

      if ($Operation->state === OperationStates::SSLRequest) {
         if ($this->flush($Operation) === false) {
            return $Operation;
         }

         $Operation->state = OperationStates::SSLResponse;

         return $this->await($Operation, Scheduler::SCHEDULE_READ);
      }

      if ($Operation->state === OperationStates::SSLResponse) {
         return $this->secure($Operation);
      }

      if ($Operation->state === OperationStates::SSLHandshake) {
         $encrypted = $this->Connection->encrypt();

         if ($encrypted === true) {
            $this->encrypted = true;
            $this->Connection->transition(ConnectionStates::Startup);
            $this->Authentication->authenticated = false;
            $Operation->write = $this->Encoder->encode(Encoder::STARTUP, $this->Config);
            $Operation->state = OperationStates::Startup;

            return $this->advance($Operation);
         }

         if ($encrypted === null) {
            return $this->await($Operation, Scheduler::SCHEDULE_WRITE);
         }

         $Operation->quarantine = true;

         return $Operation->fail('PostgreSQL TLS handshake failed.');
      }

      if ($Operation->state === OperationStates::Startup) {
         if ($this->flush($Operation) === false) {
            return $Operation;
         }

         $Operation->state = OperationStates::Authenticating;

         return $this->await($Operation, Scheduler::SCHEDULE_READ);
      }

      if ($Operation->state === OperationStates::Authenticating) {
         $state = $this->read($Operation);

         if ($state !== OperationStates::Authenticating) {
            return $this->advance($Operation);
         }

         return $this->await($Operation, Scheduler::SCHEDULE_READ);
      }

      if ($Operation->state === OperationStates::Password) {
         if ($this->flush($Operation) === false) {
            return $Operation;
         }

         $Operation->state = OperationStates::Authenticating;

         return $this->await($Operation, Scheduler::SCHEDULE_READ);
      }

      if ($Operation->state === OperationStates::Querying) {
         $Holder = $this->writing;

         // ? A co-located sibling holds the write stream — wait so the
         //   pipelined wire messages are not interleaved on the socket.
         if ($Holder !== null && $Holder !== $Operation && $Holder->finished === false) {
            // ? A holder inside its own deadline is a live writer: its caller
            //   still advances it and the flush resumes there. Past it, nobody
            //   ever advances the holder again — Pool::wait() drives only the
            //   operation it was handed — so this operation reconciles the
            //   stream itself. The transport stays up: the backend is healthy,
            //   waiting for bytes that sit in the holder's own buffer.
            if ($Holder->deadline <= 0.0 || microtime(true) < $Holder->deadline) {
               return $this->await($Operation, Scheduler::SCHEDULE_WRITE);
            }

            // ? The caller withdrew this work and its outcome is no longer safe
            //   to discover by completing the batch. Credited plaintext bytes
            //   prove reach; TLS can retain a pending record even at zero, so
            //   both cases make the session impossible to resynchronise.
            if ($Holder->revoked && ($this->wrote > 0 || $this->encrypted)) {
               $this->abort($Holder, 'PostgreSQL operation was withdrawn while writing its batch.');
            }
            elseif ($this->wrote === 0 && $this->encrypted === false) {
               // ! Plaintext copied none of this batch into the stream — nothing
               //   is owed: withdraw it and leave the operation to its own pool.
               //   It is past its deadline, so the pool expires it through the
               //   envelope that settles its claim. Its bytes never ran, so it
               //   is deliberately not revoked and fallback remains legal.
               $this->withdraw($Holder);
            }
            elseif ($this->flush($Holder) === false) {
               // ?: Still partial or held by TLS as an uncredited pending record:
               //    preserve this exact buffer, park and retry on the next pass.
               //    A hard failure routed through abort() and freed the stream.
               if ($this->writing === $Holder) {
                  return $this->await($Operation, Scheduler::SCHEDULE_WRITE);
               }
            }
            else {
               // ! The batch is whole on the wire now — the same bookkeeping a
               //   self-completed flush performs, with the answer handed to a
               //   detached stand-in exactly as abandon() does.
               $this->writing = null;
               $this->carrying = [];
               $this->free($Holder);

               $marker = $Holder->statement === ''
                  ? null
                  : ($this->preparing[$Holder->statement] ?? null);

               if (
                  $Holder->prepared === false
                  && $marker instanceof WeakReference
                  && $marker->get() === $Holder
               ) {
                  $this->preparing[$Holder->statement] = true;
               }

               $Stand = new Operation(null, $Holder->SQL);
               $Stand->state = OperationStates::Reading;
               $Stand->statement = $Holder->statement;
               $Stand->portal = $Holder->portal;
               $Stand->prepared = $Holder->prepared;

               $this->pipeline[] = $Stand;
               $this->abandoned[spl_object_id($Stand)] = true;

               // ! The work ran with an outcome its caller never sees —
               //   `revoked` keeps fallback() from running it a second time.
               $Holder->revoked = true;
               $Holder->expire();
            }
         }

         if ($Operation->write === '') {
            $this->prepare($Operation);
            $Operation->state = OperationStates::Querying;
         }

         // ? This batch Binds a name whose Parse is composed but still sitting
         //   in another operation's buffer, and nothing orders that flush ahead
         //   of this one — whichever operation the caller advances first is the
         //   one that writes first, so this Bind would reach the backend before
         //   the Parse and come back as `prepared statement "…" does not exist`.
         //   Waiting for the owner is not available: a caller awaiting this
         //   operation advances only this one, so deferring hangs both. Take the
         //   Parse instead and strip the owner back to nothing, exactly as
         //   abandon() does for the warm Binds of a batch that will never be
         //   sent — advance() re-derives it, and by then this Parse is on the
         //   wire, so it comes back as the warm Bind it was.
         if ($Operation->prepared && $Operation->statement !== '') {
            $marker = $this->preparing[$Operation->statement] ?? null;
            $Owner = $marker instanceof WeakReference ? $marker->get() : null;

            // ? Not while this batch is re-entering a partial flush: its first
            //   bytes are already on the wire, so re-composing it would send a
            //   Parse behind the Bind it belongs in front of. A live owner
            //   mid-flush cannot reach here at all — the write-stream guard
            //   above returns first.
            //
            //   And only an owner still assigned to this driver may be stripped:
            //   fallback() retries an operation onto another pool, where it
            //   composes a batch of its own, and blanking that from here would
            //   corrupt a stream this driver does not own.
            if (
               $Owner !== null
               && $Owner !== $Operation
               && $Owner->Protocol === $this
               && $this->writing !== $Operation
            ) {
               if ($Owner->finished === false) {
                  $Owner->write = '';
                  $Owner->prepared = false;
               }

               unset($this->preparing[$Operation->statement]);
               unset($this->Holders[$Operation->statement][spl_object_id($Operation)]);

               if (($this->Holders[$Operation->statement] ?? null) === []) {
                  unset($this->Holders[$Operation->statement]);
               }

               $Operation->write = '';
               $Operation->prepared = false;

               $this->prepare($Operation);

               // ! prepare() rewinds the state to Queued, which is the branch
               //   that reconnects. Leaving it there parks an operation holding
               //   the write stream as "nothing sent yet", so a connection lost
               //   during a partial flush re-handshakes instead of failing and
               //   keeps a statement cache the new session never Parsed.
               $Operation->state = OperationStates::Querying;
            }
         }

         // ! Pending statement Closes ride ahead of this batch — rendered once
         //   per batch: a partial-flush re-entry keeps $writing === $Operation.
         if ($this->closing !== [] && $this->writing !== $Operation) {
            $closes = '';

            foreach ($this->closing as $name => $queued) {
               // ? A queued Close must never decapitate a Bind that no longer
               //   Parses the name: this batch when it binds the name warm, or
               //   any sibling composed warm and still unflushed. A batch that
               //   re-Parses the name always leads with the Close — statement
               //   identity is content-derived, so the re-Parse restores an
               //   identical statement for every holder behind it.
               $held = $name === $Operation->statement
                  ? $Operation->prepared
                  : $this->scan($name);

               if ($held) {
                  continue;
               }

               $closes .= $this->Encoder->encode(Encoder::CLOSE, [
                  'type' => 'S',
                  'name' => $name,
               ]);
               unset($this->closing[$name]);
               $this->carrying[] = $name;
            }

            $Operation->write = "{$closes}{$Operation->write}";
         }

         // ! A fresh claim starts the on-the-wire count at zero; a partial-flush
         //   re-entry keeps what its earlier passes already wrote.
         if ($this->writing !== $Operation) {
            $this->writing = $Operation;
            $this->wrote = 0;
         }

         if ($this->flush($Operation) === false) {
            // @ A partial write keeps the stream held; on a hard failure the
            //   operation is finished and the guard above treats it as free.
            return $Operation;
         }

         $this->writing = null;
         $this->carrying = [];
         $this->free($Operation);

         // @ The Parse this batch carried is on the wire now, so a sibling may
         //   Bind the name warm from here on: the backend reads the socket in
         //   order and cannot see the Bind first.
         $marker = $Operation->statement === ''
            ? null
            : ($this->preparing[$Operation->statement] ?? null);

         if (
            $Operation->prepared === false
            && $marker instanceof WeakReference
            && $marker->get() === $Operation
         ) {
            $this->preparing[$Operation->statement] = true;
         }

         $Operation->state = OperationStates::Reading;
         $this->queue($Operation);

         return $this->await($Operation, Scheduler::SCHEDULE_READ);
      }

      if ($Operation->state === OperationStates::Reading) {
         $state = $this->read($Operation);

         if ($state === OperationStates::Finished || $state === OperationStates::Failed) {
            return $Operation;
         }

         return $this->await($Operation, Scheduler::SCHEDULE_READ);
      }

      return $Operation;
   }

   /**
    * Send one PostgreSQL CancelRequest through a separate connection.
    *
    * PostgreSQL cancellation is advisory: this side-channel request does not
    * abort the in-flight read path. The operation remains pending until the
    * backend reports ErrorResponse/ReadyForQuery on the main socket, or until
    * the operation deadline expires.
    */
   public function cancel (DatabaseOperation $Operation): DatabaseOperation
   {
      if ($Operation instanceof Operation === false) {
         return $Operation->fail('PostgreSQL requires an SQL operation.');
      }

      /** @var Operation $Operation */

      if ($this->backendProcess <= 0 || $this->backendSecret <= 0) {
         return $Operation->fail('PostgreSQL cancellation requires BackendKeyData.');
      }

      $target = "tcp://{$this->Config->host}:{$this->Config->port}";
      $errorCode = 0;
      $error = '';
      $socket = @stream_socket_client($target, $errorCode, $error, $this->Config->timeout);

      if ($socket === false) {
         $message = $error !== '' ? $error : 'native stream returned false';

         return $Operation->fail("PostgreSQL cancel connection failed: {$message}.");
      }

      $packet = $this->Encoder->encode(Encoder::CANCEL, [
         'process' => $this->backendProcess,
         'secret' => $this->backendSecret,
      ]);
      $written = @fwrite($socket, $packet);
      fclose($socket);

      if ($written !== strlen($packet)) {
         return $Operation->fail('PostgreSQL cancel request write failed.');
      }

      $Operation->cancelled = true;

      return $Operation;
   }

   /**
    * Reconcile the wire when the pool abandons one operation.
    *
    * A pipelined batch always ends in Sync, so the backend keeps answering an
    * operation the pool has already finished. The slot is handed to a
    * detached stand-in that absorbs the remaining messages up to its
    * ReadyForQuery — the driver keeps every session effect it owes itself
    * (ParseComplete, ParameterDescription, evictions) while the object the
    * pool took back is never read, written or resolved here again. When
    * nothing can be reconciled — the batch is half written, or no sibling is
    * left to pump the answer — the session is dropped instead.
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

      // ? Not pipelined here — an operation only joins the pipeline once its
      //   batch is whole on the wire, so nothing is owed to this one.
      if ($index === null) {
         // ? Unless it still holds a stream that cannot safely forget its
         //   buffer. Credited bytes are partial work on every transport; TLS
         //   may retain an uncredited record too. Pool expiry has already
         //   destroyed the only buffer either state could use to recover.
         if (
            $this->writing === $Operation
            && ($this->wrote > 0 || $this->encrypted)
         ) {
            $this->abort($Operation, 'PostgreSQL operation was abandoned while writing its batch.');

            return;
         }

         // @ A plaintext zero-credit batch contributed nothing to the stream:
         //   free its claim and release the Parse name it composed, along with
         //   every sibling that Bound that name warm.
         if ($Operation instanceof Operation) {
            $this->withdraw($Operation);
         }

         return;
      }

      /** @var Operation $Operation */

      // ? Draining needs a reader: the pool has already released this
      //   operation, so only a sibling advance still pumps this socket.
      $readers = 0;

      foreach ($this->pipeline as $Queued) {
         if ($Queued !== $Operation && isset($this->abandoned[spl_object_id($Queued)]) === false) {
            $readers++;
         }
      }

      // @ The batch still being written is a reader too — it is the operation
      //   its caller is actively advancing, and it joins the pipeline behind
      //   this slot as soon as its bytes are whole on the wire.
      $Writing = $this->writing;

      if ($Writing !== null && $Writing !== $Operation && $Writing->finished === false) {
         $readers++;
      }

      if ($readers === 0) {
         $this->abort($Operation, 'PostgreSQL abandoned batch has no reader left to drain its answer.');

         return;
      }

      // @ Detach the operation from the pipeline slot it still owns.
      $Stand = new Operation(null, $Operation->SQL);
      $Stand->state = OperationStates::Reading;
      $Stand->statement = $Operation->statement;
      $Stand->portal = $Operation->portal;
      $Stand->prepared = $Operation->prepared;

      $this->pipeline[$index] = $Stand;
      $this->abandoned[spl_object_id($Stand)] = true;
   }

   /**
    * Check whether this connection still has pipelined operations.
    */
   public function check (): bool
   {
      // @@ A finished entry is not work in flight: the read loop keeps it only
      //    until the message that terminates it arrives, and it stays at its
      //    slot to absorb that message whoever reads next. Reading a non-empty
      //    FIFO as "still busy" gates release() off for the connection's life
      //    when the batch that failed was answered in two reads.
      foreach ($this->pipeline as $Queued) {
         if ($Queued->finished === false) {
            return true;
         }
      }

      // :
      return false;
   }

   /**
    * Drain operations completed while reading pipelined backend messages.
    *
    * @return array<int,Operation>
    */
   public function drain (): array
   {
      $Completed = $this->completed;
      $this->completed = [];

      return $Completed;
   }

   /**
    * Sever this session, failing everything it still owes.
    */
   public function sever (DatabaseOperation $Operation, string $error): void
   {
      // ? Not this driver's own operation — the base contract still ends the
      //   session, it just has no pipeline of its own to hand back.
      if ($Operation instanceof Operation === false) {
         parent::sever($Operation, $error);

         return;
      }

      $this->abort($Operation, $error);
   }

   /**
    * Abort the session after a transport failure.
    *
    * A dead socket can never deliver the responses the pipeline is waiting
    * for: every pipelined operation fails, the session state (server-side
    * prepared statements, packet buffer, write holder) dies with the socket
    * and the connection is disconnected so the pool drops it instead of
    * keeping it busy forever.
    */
   private function abort (Operation $Operation, string $error): Operation
   {
      // ! Session state — packets and named statements die with the socket
      $this->statements = [];
      $this->layouts = [];
      $this->preparing = [];
      $this->closing = [];
      $this->Holders = [];
      $this->abandoned = [];
      $this->wrote = 0;
      $this->carrying = [];
      $this->Decoder = new Decoder;

      // ! So does the half-written batch. An operation joins the pipeline only
      //   once its bytes are whole on the wire, so this one is in neither the
      //   pipeline nor completed[] and the loop below never reaches it: nulling
      //   the pointer alone left its caller unfinished and errorless on a
      //   session that no longer exists. Failing it also discards its buffer,
      //   which must never reach the next socket — the tail of a message would
      //   be read there as a message of its own.
      $Writer = $this->writing;
      $this->writing = null;

      if ($Writer !== null && $Writer->finished === false) {
         $Writer->quarantine = true;
         $Writer->fail($error);

         if ($Writer !== $Operation) {
            $this->completed[] = $Writer;
         }
      }

      $Pipeline = $this->pipeline;
      $this->pipeline = [];

      // @@ Pipelined operations — completed[] hands the siblings to Pool::drain()
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

      // @ Drop the transport — the pool releases the connection as dead.
      $this->Connection->disconnect();
      $this->aborted = true;

      // :
      return $Operation;
   }

   /**
    * Withdraw a plaintext batch that the stream accepted no bytes from.
    */
   private function withdraw (Operation $Operation): void
   {
      // ? The callers admit only plaintext zero-credit claims. A batch with
      //   accepted bytes — or any zero-return TLS write — may need this exact
      //   remainder to complete the stream and must not be freed here.
      if ($this->writing === $Operation && $this->wrote === 0) {
         $this->writing = null;

         // @ The Closes this batch carried never went out — requeue them, or
         //   the backend keeps names the driver believes closed.
         foreach ($this->carrying as $name) {
            $this->closing[$name] = true;
         }

         $this->carrying = [];
      }

      // ? It composed a Parse the backend will never receive — releasing the
      //   name lets the next operation Parse it, instead of Binding a statement
      //   nothing ever registered. Only the operation that composed the Parse
      //   may release the name: a sibling clearing a marker it does not own
      //   would let the next one Parse a statement already on the wire (42P05).
      $statement = $Operation->statement;
      $marker = $statement === '' ? null : ($this->preparing[$statement] ?? null);

      if (
         isset($this->statements[$statement])
         || $marker instanceof WeakReference === false
         || $marker->get() !== $Operation
      ) {
         return;
      }

      unset($this->preparing[$statement]);

      // @@ Siblings composed a warm Bind against that Parse. It is never being
      //    sent, so their batches would name a statement the backend does not
      //    have: strip them back to nothing and let advance() re-derive each
      //    one. The first to reach the wire composes the Parse itself. A
      //    half-written batch is untouchable — it already owns bytes on the
      //    socket.
      foreach ($this->Holders[$statement] ?? [] as $id => $Held) {
         if ($Held->finished || $Held->write === '' || $this->writing === $Held) {
            continue;
         }

         $Held->write = '';
         $Held->prepared = false;
         unset($this->Holders[$statement][$id]);
      }

      if (($this->Holders[$statement] ?? []) === []) {
         unset($this->Holders[$statement]);
      }
   }

   /**
    * Release one in-flight Parse marker its composer no longer owes.
    */
   private function release (string $statement, bool $answered = false): void
   {
      $marker = $this->preparing[$statement] ?? null;

      // ? A Parse already on the wire remains in flight until a backend answer
      //   settles it. A name-only cache eviction belongs to no particular
      //   operation and must not erase that fact: a queued Close could then
      //   overtake the unanswered Parse and leave the cache ahead of the server.
      if ($marker === true && $answered === false) {
         return;
      }

      // ? A live composer still holds that Parse in its buffer, so this event
      //   describes a different registration of the name. Dropping the marker
      //   would strand it: a warm sibling could then neither read it nor take
      //   it, and would reach the wire with a Bind for a statement nothing has
      //   Parsed — which is the very defect the marker exists to prevent.
      if ($marker instanceof WeakReference) {
         $Composer = $marker->get();

         if ($Composer !== null && $Composer->finished === false) {
            return;
         }
      }

      unset($this->preparing[$statement]);
   }

   /**
    * Cache prepared statement metadata.
    *
    * @param bool|array<int,int> $metadata
    */
   public function cache (string $statement, bool|array $metadata = true): self
   {
      if ($statement === '') {
         return $this;
      }

      $this->release($statement);
      $this->statements[$statement] = $metadata;

      return $this;
   }

   /**
    * Evict prepared statement metadata.
    */
   public function evict (string $statement): self
   {
      // ?
      if ($statement === '') {
         return $this;
      }

      $this->release($statement);
      unset($this->statements[$statement], $this->layouts[$statement]);

      // ! Pair every client-side removal with a wire Close on the next batch.
      //   A Close for a name the backend never registered is harmless.
      $this->closing[$statement] = true;

      return $this;
   }

   /**
    * Scan for a composed batch still binding one prepared statement name.
    */
   private function scan (string $statement): bool
   {
      // ? A sent Parse holds the name until ParseComplete or ErrorResponse. A
      //   Close rendered now would follow that Parse on the socket, destroy its
      //   registration, and leave the next warm Bind naming nothing.
      if (($this->preparing[$statement] ?? null) === true) {
         return true;
      }

      $Holders = $this->Holders[$statement] ?? [];

      // @@ Holders — a flushed or finished operation no longer holds anything:
      //    its Bind bytes are already ordered ahead of any later Close.
      foreach ($Holders as $id => $Held) {
         if ($Held->finished || $Held->write === '') {
            unset($this->Holders[$statement][$id]);

            continue;
         }

         // :
         return true;
      }

      if (($this->Holders[$statement] ?? []) === []) {
         unset($this->Holders[$statement]);
      }

      // :
      return false;
   }

   /**
    * Free one operation's hold on its prepared statement name.
    */
   private function free (Operation $Operation): void
   {
      $statement = $Operation->statement;

      // ?
      if (isset($this->Holders[$statement]) === false) {
         return;
      }

      unset($this->Holders[$statement][spl_object_id($Operation)]);

      if ($this->Holders[$statement] === []) {
         unset($this->Holders[$statement]);
      }
   }

   /**
    * Discard transient statement metadata after a batch completes.
    */
   private function discard (Operation $Operation): self
   {
      // ?
      if ($this->SQLConfig->statements > 0 || $Operation->statement === '') {
         return $this;
      }

      // ! With a zero statements budget every statement is transient — evict
      //   queues the wire Close that frees the backend entry.
      $this->evict($Operation->statement);
      $Operation->prepared = false;

      return $this;
   }

   /**
    * Identify this connection with backend cancellation keys.
    */
   public function identify (int $process, int $secret): self
   {
      $this->backendProcess = $process;
      $this->backendSecret = $secret;

      return $this;
   }

   /**
    * Record one backend parameter status.
    */
   public function record (string $name, string $value): self
   {
      if ($name === '') {
         return $this;
      }

      $this->parameters[$name] = $value;

      return $this;
   }

   /**
    * Notice one backend message.
    *
    * @param array<string,mixed> $notice
    */
   public function notice (array $notice): self
   {
      $this->notices[] = $notice;

      return $this;
   }

   /**
    * Notify one backend asynchronous message.
    */
   public function notify (int $process, string $channel, string $payload): self
   {
      $this->notifications[] = [
         'process' => $process,
         'channel' => $channel,
         'payload' => $payload,
      ];

      return $this;
   }

   /**
    * Wait for socket readiness.
    *
    * Reuses one read- and one write-Readiness per socket so the hot advance
    * path does not allocate a Readiness object on every suspension. The cache
    * is rebuilt only when the underlying socket changes (reconnect, attach).
    */
   private function await (Operation $Operation, int $flag): Operation
   {
      $socket = $this->Connection->socket;

      if (is_resource($socket) === false) {
         return $this->abort($Operation, 'PostgreSQL socket is not available.');
      }

      // @ Invalidate Readiness cache when the socket changes.
      if ($this->cachedSocket !== $socket) {
         $this->cachedSocket = $socket;
         $this->ReadReadiness = null;
         $this->WriteReadiness = null;
      }

      if ($flag === Scheduler::SCHEDULE_WRITE) {
         $Readiness = $this->WriteReadiness
            ?? ($this->WriteReadiness = Readiness::write($socket, $Operation->deadline));
         $Readiness->renew($Operation->deadline);
      }
      else {
         $Readiness = $this->ReadReadiness
            ?? ($this->ReadReadiness = Readiness::read($socket, $Operation->deadline));
         $Readiness->renew($Operation->deadline);
      }

      $Operation->await($Readiness);

      return $Operation;
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
         $this->abort($Operation, 'PostgreSQL socket is not available.');

         return false;
      }

      $written = @fwrite($socket, $Operation->write);

      if ($written === false) {
         $this->abort($Operation, 'PostgreSQL socket write failed.');

         return false;
      }

      if ($written === 0) {
         if (feof($socket)) {
            $this->abort($Operation, 'PostgreSQL socket closed during write.');

            return false;
         }

         $this->await($Operation, Scheduler::SCHEDULE_WRITE);

         return false;
      }

      // ! Only the write-stream holder is counted — connect and authentication
      //   flushes run with no claim on the stream.
      if ($this->writing === $Operation) {
         $this->wrote += $written;
      }

      $Operation->write = substr($Operation->write, $written);

      if ($Operation->write !== '') {
         $this->await($Operation, Scheduler::SCHEDULE_WRITE);

         return false;
      }

      return true;
   }

   /**
    * Read SSLRequest response and transition TLS mode.
    */
   private function secure (Operation $Operation): Operation
   {
      $socket = $this->Connection->socket;

      if (is_resource($socket) === false) {
         return $this->abort($Operation, 'PostgreSQL socket is not available.');
      }

      $response = @fread($socket, 1);

      if ($response === false) {
         return $this->abort($Operation, 'PostgreSQL SSL response read failed.');
      }

      if ($response === '') {
         if (feof($socket)) {
            return $this->abort($Operation, 'PostgreSQL socket closed during SSL negotiation.');
         }

         return $this->await($Operation, Scheduler::SCHEDULE_READ);
      }

      if ($response === 'S') {
         $Operation->state = OperationStates::SSLHandshake;

         return $this->await($Operation, Scheduler::SCHEDULE_WRITE);
      }

      if ($response === 'N') {
         $mode = $this->Config->secure['mode'];

         if ($mode !== Config::SECURE_PREFER) {
            $Operation->quarantine = true;
            $Operation->fail('PostgreSQL server refused required TLS.');

            return $Operation;
         }

         $this->Connection->transition(ConnectionStates::Startup);
         $this->Authentication->authenticated = false;
         $Operation->write = $this->Encoder->encode(Encoder::STARTUP, $this->Config);
         $Operation->state = OperationStates::Startup;

         $this->advance($Operation);

         return $Operation;
      }

   $Operation->quarantine = true;
      $Operation->fail('PostgreSQL SSL response is invalid.');

      return $Operation;
   }

   /**
    * Read and apply backend messages from the socket.
    */
   private function read (Operation $Operation): OperationStates
   {
      $socket = $this->Connection->socket;

      if (is_resource($socket) === false) {
         $this->abort($Operation, 'PostgreSQL socket is not available.');

         return $Operation->state;
      }

      $bytes = @fread($socket, 8192);

      if ($bytes === false) {
         $this->abort($Operation, 'PostgreSQL socket read failed.');

         return $Operation->state;
      }

      if ($bytes === '') {
         if (feof($socket)) {
            $this->abort($Operation, 'PostgreSQL socket closed.');

            return $Operation->state;
         }

         return $Operation->state;
      }

      try {
         $Messages = $this->Decoder->decode($bytes);
      }
      catch (Throwable $Throwable) {
         // ? Framing corruption cannot be resynchronized — kill the session.
         $this->abort($Operation, $Throwable->getMessage());

         return $Operation->state;
      }

      foreach ($Messages as $Message) {
         $Active = $this->pipeline[0] ?? ($Operation->finished ? null : $Operation);

         if ($Active === null) {
            if ($Message->type === 'K' || $Message->type === 'S' || $Message->type === 'N' || $Message->type === 'A') {
               $this->apply($Operation, $Message);
            }

            continue;
         }

         $this->apply($Active, $Message);

         if ($Active->finished && ($Message->type === 'Z' || $Active->state === OperationStates::Finished)) {
            array_shift($this->pipeline);

            // ? A stand-in is not an operation the pool ever assigned:
            //   draining it must not release a connection a second time.
            $id = spl_object_id($Active);

            if (isset($this->abandoned[$id])) {
               unset($this->abandoned[$id]);
            }
            else {
               $this->completed[] = $Active;
            }
         }
      }

      return $Operation->state;
   }

   /**
    * Queue one operation as in-flight for ordered backend responses.
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
    * Apply a decoded backend message to an operation.
    *
    * Branch order matches per-query message frequency: every result emits
    * D × N + T + C + Z, so those four are checked first. Prepare-time and
    * connect-time messages live below.
    */
   private function apply (Operation $Operation, Message $Message): Operation
   {
      $type = $Message->type;

      if ($type === 'D') {
         $row = [];
         $values = $Message->fields['values'] ?? [];

         if (is_array($values)) {
            $columns = $Operation->columns;
            $types = $Operation->types;

            foreach ($values as $index => $value) {
               $key = $columns[$index] ?? (string) $index;
               $row[$key] = $this->cast($value, $types[$index] ?? 0);
            }

            $Operation->rows[] = $row;
         }

         return $Operation;
      }

      if ($type === 'C') {
         $command = $Message->fields['command'] ?? '';

         if (is_scalar($command) === false) {
            $command = '';
         }

         $command = (string) $command;
         $Operation->status = $command;

         // ? The backend answers a COMMIT with the ROLLBACK tag when the
         //   transaction block was aborted — some earlier statement in it
         //   failed and the caller carried on from the error. The tag is the
         //   only place that is reported: no ErrorResponse follows, so the
         //   operation carries no error of its own and used to resolve as a
         //   successful commit while the server had discarded every write in
         //   the transaction.
         if ($command === 'ROLLBACK' && str_starts_with(strtolower(ltrim($Operation->SQL)), 'commit')) {
            return $Operation->fail(
               'SQL transaction was rolled back by the server: a statement inside it had failed.'
            );
         }

         $parts = explode(' ', $command);
         $last = $parts[count($parts) - 1] ?? '0';
         $Operation->affected = is_numeric($last) ? (int) $last : 0;

         return $Operation;
      }

      if ($type === 'Z') {
         // ? A stand-in only drains an abandoned answer: nothing is resolved
         //   with it and no query event is emitted on its behalf.
         if ($this->abandoned !== [] && isset($this->abandoned[spl_object_id($Operation)])) {
            $this->discard($Operation);
            $this->Connection->transition();

            return $Operation->fail('PostgreSQL abandoned batch answer was drained.');
         }

         if ($Operation->state === OperationStates::Failed) {
            $this->discard($Operation);
            $this->Connection->transition();

            return $Operation;
         }

         if ($Operation->state === OperationStates::Authenticating || $Operation->state === OperationStates::Password) {
            if ($this->Authentication->authenticated === false) {
               return $Operation->fail('PostgreSQL authentication completed without AuthenticationOk.');
            }

            $this->Connection->transition();
            $Operation->state = OperationStates::Querying;

            return $Operation;
         }

         $this->discard($Operation);
         $this->Connection->transition();

         return $Operation->resolve(new Result(
            $Operation->status,
            $Operation->rows,
            $Operation->columns,
            $Operation->affected
         ));
      }

      if ($type === 'T') {
         $Operation->columns = [];
         $Operation->types = [];
         $columns = $Message->fields['columns'] ?? [];

         if (is_array($columns)) {
            foreach ($columns as $column) {
               if (is_array($column) && isset($column['name']) && is_string($column['name'])) {
                  $Operation->columns[] = $column['name'];
                  $columnType = $column['type'] ?? 0;
                  $Operation->types[] = is_int($columnType) ? $columnType : 0;
               }
            }
         }

         // ! Record the layout under the statement that produced it: every later
         //   Bind of the same name answers with this same RowDescription, so the
         //   warm batch can stop asking for it (see prepare()).
         //
         //   ! The cache entry is the gate, not the name. A RowDescription still
         //   in flight when its statement is evicted would otherwise re-create a
         //   layout the driver no longer holds; the next cold re-Parse of that
         //   content-derived name restores the cache entry at ParseComplete, and
         //   a sibling composed before the new RowDescription would apply the
         //   PREVIOUS registration's columns and type OIDs. The backend cannot
         //   catch that — its plan is new, so `cached plan must not change result
         //   type` never fires — and the rows come back silently mislabelled and
         //   mis-cast.
         if ($Operation->statement !== '' && isset($this->statements[$Operation->statement])) {
            $this->layouts[$Operation->statement] = [
               'columns' => $Operation->columns,
               'types' => $Operation->types,
            ];
         }

         return $Operation;
      }

      // @ NoData — the statement produces no result columns. That is a layout
      //   too: caching it lets the warm batch skip its portal Describe. Same
      //   gate as the RowDescription branch above.
      if ($type === 'n') {
         if ($Operation->statement !== '' && isset($this->statements[$Operation->statement])) {
            $this->layouts[$Operation->statement] = ['columns' => [], 'types' => []];
         }

         return $Operation;
      }

      // @ Extended query no-ops — Bind / PortalSuspended / CloseComplete.
      if ($type === '2' || $type === 's' || $type === '3') {
         return $Operation;
      }

      if ($type === '1') {
         // ! Unconditional — the driver model mirrors the backend truth; a
         //   zero statements budget discards transiently at ReadyForQuery.
         if ($Operation->statement !== '') {
            // @ ParseComplete settles this operation's sent marker. Any Close
            //   queued while the Parse was unanswered described an older
            //   registration and is stale now that this one exists.
            $this->release($Operation->statement, answered: true);
            // ! A ParseComplete announces a NEW registration of this name. Any
            //   layout still held describes the old one and must not survive
            //   into the window this cache() opens.
            unset($this->closing[$Operation->statement], $this->layouts[$Operation->statement]);
            $this->cache($Operation->statement);
            $Operation->prepared = true;
         }

         return $Operation;
      }

      if ($type === 't') {
         $parameters = $Message->fields['parameters'] ?? [];
         $Operation->parameterTypes = [];

         if (is_array($parameters)) {
            foreach ($parameters as $parameter) {
               $Operation->parameterTypes[] = is_int($parameter) ? $parameter : 0;
            }
         }

         if ($Operation->statement !== '') {
            $this->cache($Operation->statement, $Operation->parameterTypes);
         }

         return $Operation;
      }

      if ($type === 'E') {
         $message = $Message->fields['message'] ?? 'PostgreSQL error.';
         // ! SQLSTATE — the locale-independent failure identity (field `C`)
         $code = $Message->fields['code'] ?? '';
         $code = is_string($code) ? $code : '';

         if ($Operation->statement !== '') {
            if ($Operation->prepared === false) {
               // ? No ParseComplete arrived — the backend never registered the
               //   statement. This ErrorResponse is therefore the protocol
               //   answer that may release its sent marker.
               $this->release($Operation->statement, answered: true);
               $this->evict($Operation->statement);
               $Operation->prepared = false;
            }
            elseif (isset($this->statements[$Operation->statement]) === false) {
               // ? A warm operation can outlive the cache entry it used. Its
               //   error does not answer a newer sibling's Parse for the same
               //   content-derived name, so ordinary eviction preserves that
               //   sent marker until its own response arrives.
               $this->evict($Operation->statement);
               $Operation->prepared = false;
            }
            else {
               // ? Only errors that invalidate the server-side statement evict
               //   it — a runtime SQLSTATE (duplicate key, division by zero,
               //   timeout...) leaves the prepared statement usable.
               if ($code === '' || $code === '0A000' || $code === '26000') {
                  $this->evict($Operation->statement);
                  $Operation->prepared = false;
               }
            }
         }

         if (is_scalar($message) === false) {
            $message = 'PostgreSQL error.';
         }

         return $Operation->fail((string) $message, $code === '' ? null : $code);
      }

      if ($type === 'R') {
         $code = $Message->fields['code'] ?? -1;

         if (is_int($code) === false) {
            $code = -1;
         }

         if ($code === 0) {
            $this->Authentication->authenticated = true;

            // @ Events — SQL connection authenticated (guarded: zero-alloc when no listeners)
            $Emitter = Emitter::$Instance;
            $Emitter->check(Events::Connected) && $Emitter->emit(Events::Connected, $this->Connection);

            return $Operation;
         }

         $Encoder = $this->Encoder;

         if ($code === 3) {
            $Operation->write = $Encoder->encode(Encoder::PASSWORD, $this->Config->password);
            $Operation->state = OperationStates::Password;

            return $Operation;
         }

         if ($code === 5) {
            $salt = $Message->fields['salt'] ?? '';

            if (is_string($salt) === false) {
               return $Operation->fail('PostgreSQL MD5 authentication salt is invalid.');
            }

            $Operation->write = $Encoder->encode(Encoder::PASSWORD, $this->Authentication->hash($salt));
            $Operation->state = OperationStates::Password;

            return $Operation;
         }

         if ($code === 10) {
            $mechanisms = $Message->fields['mechanisms'] ?? [];

            if (is_array($mechanisms) === false) {
               return $Operation->fail('PostgreSQL SASL mechanisms are invalid.');
            }

            $mechanismList = [];

            foreach ($mechanisms as $mechanism) {
               if (is_string($mechanism)) {
                  $mechanismList[] = $mechanism;
               }
            }

            $Operation->write = $Encoder->encode(Encoder::SASL, $this->Authentication->start($mechanismList));
            $Operation->state = OperationStates::Password;

            return $Operation;
         }

         if ($code === 11) {
            $message = $Message->fields['data'] ?? '';

            if (is_string($message) === false) {
               return $Operation->fail('PostgreSQL SASL continue message is invalid.');
            }

            $Operation->write = $Encoder->encode(Encoder::RESPONSE, $this->Authentication->resume($message));
            $Operation->state = OperationStates::Password;

            return $Operation;
         }

         if ($code === 12) {
            $message = $Message->fields['data'] ?? '';

            if (is_string($message) === false || $this->Authentication->finish($message) === false) {
               return $Operation->fail('PostgreSQL SASL server signature is invalid.');
            }

            return $Operation;
         }

         return $Operation->fail("PostgreSQL authentication method is not supported: {$code}.");
      }

      if ($type === 'K') {
         $process = $Message->fields['process'] ?? 0;
         $secret = $Message->fields['secret'] ?? 0;
         $this->identify(
            is_int($process) ? $process : 0,
            is_int($secret) ? $secret : 0
         );

         return $Operation;
      }

      if ($type === 'S') {
         $name = $Message->fields['name'] ?? '';
         $value = $Message->fields['value'] ?? '';

         if (is_string($name) && $name !== '' && is_string($value)) {
            $this->record($name, $value);
         }

         return $Operation;
      }

      if ($type === 'N') {
         $notice = $Message->fields['notice'] ?? [];
         $this->notice(is_array($notice) ? $notice : []);

         return $Operation;
      }

      if ($type === 'A') {
         $process = $Message->fields['process'] ?? 0;
         $channel = $Message->fields['channel'] ?? '';
         $payload = $Message->fields['payload'] ?? '';
         $this->notify(
            is_int($process) ? $process : 0,
            is_string($channel) ? $channel : '',
            is_string($payload) ? $payload : ''
         );

         return $Operation;
      }

      return $Operation;
   }

   /**
    * Cast one text-format PostgreSQL value to a PHP scalar when safe.
    */
   private function cast (mixed $value, int $type): mixed
   {
      if ($value === null || is_string($value) === false) {
         return $value;
      }

      return match ($type) {
         16 => $value === 't' || $value === 'true' || $value === '1',
         17 => $this->decode($value),
         20, 21, 23 => (int) $value,
         1082, 1083, 1114, 1184, 1266 => $this->parse($value),
         700, 701 => (float) $value,
         1700 => $value,
         default => $value,
      };
   }

   /**
    * Cast one PostgreSQL temporal text value.
    */
   private function parse (string $value): DateTimeImmutable|string
   {
      try {
         return new DateTimeImmutable($value);
      }
      catch (Throwable) {
         return $value;
      }
   }

   /**
    * Cast one PostgreSQL bytea text value.
    */
   private function decode (string $value): string
   {
      if (str_starts_with($value, '\\x') === false) {
         return $value;
      }

      $binary = hex2bin(substr($value, 2));

      return $binary === false ? $value : $binary;
   }

   /**
    * Infer one PostgreSQL parameter OID.
    */
   private function infer (mixed $parameter, Operation $Operation, int $index): int
   {
      if (is_int($parameter)) {
         // ?: int4 only when the value fits — int8 otherwise, so magnitude
         //    joins the statement identity and can never truncate silently.
         return $parameter >= -2147483648 && $parameter <= 2147483647 ? 23 : 20;
      }

      if (is_bool($parameter)) {
         return 16;
      }

      if (is_float($parameter)) {
         return 701;
      }

      // ?: Strings carry no reliable OID — declaring text (25) pinned the
      //    backend to that type and broke most column targets. The cast scan
      //    below is a plain text match, so a `$N::type` written inside a
      //    literal or a comment would pin the wrong type just as hard: a
      //    string parameter is always left for the backend to infer.
      if (is_string($parameter)) {
         return 0;
      }

      $position = $index + 1;
      $pattern = '/\\$' . $position . '\\s*::\\s*([a-zA-Z_][a-zA-Z0-9_]*)(?:\\s+([a-zA-Z_][a-zA-Z0-9_]*))?/i';

      if (preg_match($pattern, $Operation->SQL, $matches) !== 1) {
         return 0;
      }

      $name = strtolower((string) ($matches[1] ?? ''));
      $second = strtolower((string) ($matches[2] ?? ''));

      if ($name === 'double' && $second === 'precision') {
         return 701;
      }

      return match ($name) {
         'boolean', 'bool' => 16,
         'smallint', 'int2' => 21,
         'integer', 'int', 'int4' => 23,
         'bigint', 'int8' => 20,
         'real', 'float4' => 700,
         'float8' => 701,
         'text', 'varchar', 'char', 'bpchar' => 25,
         default => 0,
      };
   }
}
