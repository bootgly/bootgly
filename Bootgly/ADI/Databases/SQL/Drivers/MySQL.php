<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL\Drivers;


use function array_key_first;
use function array_shift;
use function count;
use function fclose;
use function feof;
use function fread;
use function fwrite;
use function is_int;
use function is_resource;
use function is_string;
use function ord;
use function preg_match;
use function rtrim;
use function stream_socket_client;
use function strlen;
use function strpos;
use function strtoupper;
use function substr;
use DateTimeImmutable;
use Throwable;

use Bootgly\ABI\Events\Emitter;
use Bootgly\ACI\Events\Readiness;
use Bootgly\ACI\Events\Scheduler;
use Bootgly\ADI\Database\Config as DatabaseConfig;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Database\Connection\ConnectionStates;
use Bootgly\ADI\Database\Operation as DatabaseOperation;
use Bootgly\ADI\Database\Operation\OperationStates;
use Bootgly\ADI\Database\Operation\Result;
use Bootgly\ADI\Databases\SQL\Config;
use Bootgly\ADI\Databases\SQL\Driver;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL\Authentication;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL\Capabilities;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL\Decoder;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL\Encoder;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL\Message;
use Bootgly\ADI\Databases\SQL\Events;
use Bootgly\ADI\Databases\SQL\Operation;


/**
 * MySQL/MariaDB client protocol implementation.
 *
 * The MySQL protocol is strictly request-response — there is no wire
 * pipelining. Co-located operations queue in a FIFO where only the head owns
 * the wire: any sibling advance pumps the shared read stream for the head and
 * the next queued command is written when the head completes.
 */
class MySQL extends Driver
{
   // * Config
   public Authentication $Authentication;
   public Encoder $Encoder;
   public Decoder $Decoder;

   // * Data
   /** @var array<string,array{statement:int,parameters:int,columns:int}> */
   public private(set) array $statements = [];
   public private(set) int $thread = 0;
   public private(set) int $capabilities = 0;
   public private(set) string $version = '';
   public private(set) string $plugin = '';

   // * Metadata
   /** @var array<int,Operation> */
   private array $pipeline = [];
   /** @var array<int,Operation> */
   private array $completed = [];
   private string $nonce = '';
   private bool $encrypted = false;
   // # Current result set (only the pipeline head is on the wire)
   private int $expected = 0;
   /** @var array<int,array<string,int|string>> */
   private array $meta = [];
   private string $phase = '';
   private bool $draining = false;
   // @ COM_STMT_PREPARE response in flight for the pipeline head.
   private bool $preparing = false;
   // @ Parameter/column definition packets left to consume after prepare-OK.
   private int $definitions = 0;
   // @ Binary protocol rows (COM_STMT_EXECUTE) for the current result set.
   private bool $binary = false;
   // @ Pending COM_STMT_CLOSE packets from statement cache evictions.
   private string $closing = '';
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
    * Create a MySQL query operation.
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
    * Prepare an operation for MySQL execution.
    */
   public function prepare (DatabaseOperation $Operation): DatabaseOperation
   {
      if ($Operation instanceof Operation === false) {
         return $Operation->fail('MySQL requires an SQL operation.');
      }

      /** @var Operation $Operation */

      $Operation->Connection = $this->Connection;
      $Operation->Protocol = $this;
      $Operation->state = OperationStates::Queued;

      try {
         if ($Operation->parameters === []) {
            $Operation->write = $this->Encoder->encode(Encoder::QUERY, $Operation->SQL);

            return $Operation;
         }

         // # Binary protocol — prepared statements keyed by SQL text
         $Operation->statement = $Operation->SQL;
         $entry = $this->statements[$Operation->SQL] ?? null;

         if ($entry !== null) {
            // @ Cache hit — LRU touch and execute directly.
            unset($this->statements[$Operation->SQL]);
            $this->statements[$Operation->SQL] = $entry;

            $Operation->prepared = true;
            $Operation->write = $this->Encoder->encode(Encoder::EXECUTE, [
               'statement' => $entry['statement'],
               'parameters' => $Operation->parameters,
            ]);

            return $Operation;
         }

         // : Cache miss — COM_STMT_PREPARE first; EXECUTE follows the prepare-OK
         $Operation->write = $this->Encoder->encode(Encoder::PREPARE, $Operation->SQL);

         return $Operation;
      }
      catch (Throwable $Throwable) {
         return $Operation->fail($Throwable->getMessage());
      }
   }

   /**
    * Advance a MySQL operation.
    */
   public function advance (DatabaseOperation $Operation): DatabaseOperation
   {
      if ($Operation instanceof Operation === false) {
         return $Operation->fail('MySQL requires an SQL operation.');
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
         // @ The server speaks first — wait for the greeting packet.
         $this->Connection->transition(ConnectionStates::Startup);
         $this->Authentication->authenticated = false;
         $this->encrypted = false;
         $Operation->state = OperationStates::Startup;

         return $this->await($Operation, Scheduler::SCHEDULE_READ);
      }

      if ($Operation->state === OperationStates::Startup) {
         $state = $this->read($Operation);

         if ($state !== OperationStates::Startup) {
            return $this->advance($Operation);
         }

         return $this->await($Operation, Scheduler::SCHEDULE_READ);
      }

      if ($Operation->state === OperationStates::SSLRequest) {
         if ($this->flush($Operation) === false) {
            return $Operation;
         }

         $Operation->state = OperationStates::SSLHandshake;

         return $this->advance($Operation);
      }

      if ($Operation->state === OperationStates::SSLHandshake) {
         try {
            $encrypted = $this->Connection->encrypt();
         }
         catch (Throwable $Throwable) {
            $Operation->quarantine = true;

            return $Operation->fail("MySQL TLS handshake failed: {$Throwable->getMessage()}");
         }

         if ($encrypted === true) {
            $this->encrypted = true;
            $this->respond($Operation, 2);

            return $this->advance($Operation);
         }

         if ($encrypted === null) {
            return $this->await($Operation, Scheduler::SCHEDULE_WRITE);
         }

         $Operation->quarantine = true;

         return $Operation->fail('MySQL TLS handshake failed.');
      }

      if ($Operation->state === OperationStates::Password) {
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

      if ($Operation->state === OperationStates::Querying) {
         // ? Strict request-response — only the pipeline head owns the wire.
         $this->queue($Operation);

         if ($this->pipeline[0] !== $Operation) {
            // @ A sibling command is on the wire — pump its read path.
            $this->read($Operation);

            if ($Operation->finished) {
               return $Operation;
            }

            if ($this->pipeline[0] !== $Operation) {
               return $this->await($Operation, Scheduler::SCHEDULE_READ);
            }
         }

         if ($Operation->write === '') {
            $this->prepare($Operation);
            $Operation->state = OperationStates::Querying;
         }

         // ! Pending COM_STMT_CLOSE packets ride ahead of the next command.
         if ($this->closing !== '') {
            $Operation->write = $this->closing . $Operation->write;
            $this->closing = '';
         }

         if ($this->flush($Operation) === false) {
            return $Operation;
         }

         $this->clear();
         $this->preparing = $Operation->statement !== '' && $Operation->prepared === false;
         $this->binary = $Operation->prepared;
         $Operation->state = OperationStates::Reading;

         return $this->await($Operation, Scheduler::SCHEDULE_READ);
      }

      if ($Operation->state === OperationStates::Reading) {
         $state = $this->read($Operation);

         if ($state === OperationStates::Finished || $state === OperationStates::Failed) {
            return $Operation;
         }

         // ? A prepare-OK re-queued the command as COM_STMT_EXECUTE
         if ($state === OperationStates::Querying) {
            return $this->advance($Operation);
         }

         return $this->await($Operation, Scheduler::SCHEDULE_READ);
      }

      return $Operation;
   }

   /**
    * Kill the in-flight command through a separate connection.
    *
    * MySQL cancellation is advisory: the side channel authenticates a fresh
    * session and issues `KILL QUERY {thread}`. The main operation remains
    * pending until the server reports ERR/OK on the main socket, or until the
    * operation deadline expires. The side channel only supports the fast
    * authentication paths — full caching_sha2 authentication aborts.
    */
   public function cancel (DatabaseOperation $Operation): DatabaseOperation
   {
      if ($Operation instanceof Operation === false) {
         return $Operation->fail('MySQL requires an SQL operation.');
      }

      /** @var Operation $Operation */

      // ?
      if ($this->thread <= 0) {
         return $Operation->fail('MySQL cancellation requires the greeting thread id.');
      }

      $target = "tcp://{$this->Config->host}:{$this->Config->port}";
      $errorCode = 0;
      $error = '';
      $socket = @stream_socket_client($target, $errorCode, $error, $this->Config->timeout);

      if ($socket === false) {
         $message = $error !== '' ? $error : 'native stream returned false';

         return $Operation->fail("MySQL cancel connection failed: {$message}.");
      }

      try {
         // ! Side-channel session — isolated decoder, blocking I/O
         $Decoder = new Decoder;
         $Queue = [];
         $Message = $this->pull($socket, $Decoder, $Queue);

         if ($Message === null || ord($Message->payload[0] ?? "\0") === 0xFF) {
            return $Operation->fail('MySQL cancel greeting failed.');
         }

         $fields = $Decoder->read($Message->payload, 'greeting');
         $server = $fields['capabilities'] ?? 0;
         $server = is_int($server) ? $server : 0;
         $nonce = $fields['nonce'] ?? '';
         $nonce = rtrim(is_string($nonce) ? $nonce : '', "\0");
         $plugin = $fields['plugin'] ?? '';
         $plugin = is_string($plugin) && $plugin !== '' ? $plugin : Authentication::NATIVE;
         $capabilities = (
            Capabilities::PROTOCOL_41
            | Capabilities::SECURE_CONNECTION
            | Capabilities::PLUGIN_AUTH
            | Capabilities::PLUGIN_AUTH_LENENC
         ) & $server;

         $auth = $this->Authentication->scramble($plugin, $nonce);
         fwrite($socket, $this->Encoder->encode(Encoder::RESPONSE, [
            'capabilities' => $capabilities,
            'auth' => $auth,
            'plugin' => $plugin,
            'config' => $this->Config,
         ], $Message->sequence + 1));

         // @@ Authentication — fast paths only
         while (true) {
            $Message = $this->pull($socket, $Decoder, $Queue);

            if ($Message === null) {
               return $Operation->fail('MySQL cancel authentication failed.');
            }

            $first = ord($Message->payload[0] ?? "\0");

            if ($first === 0x00) {
               break;
            }

            if ($first === 0xFF) {
               return $Operation->fail('MySQL cancel authentication rejected.');
            }

            // ? AuthSwitchRequest
            if ($first === 0xFE) {
               $payload = $Message->payload;
               $stop = strpos($payload, "\0", 1);

               if ($stop === false) {
                  return $Operation->fail('MySQL cancel authentication switch is malformed.');
               }

               $plugin = substr($payload, 1, $stop - 1);
               $nonce = rtrim(substr($payload, $stop + 1), "\0");
               $auth = $this->Authentication->scramble($plugin, $nonce);
               fwrite($socket, $this->Encoder->encode(Encoder::AUTH, $auth, $Message->sequence + 1));

               continue;
            }

            // ? AuthMoreData — 0x03 fast success; anything else needs full auth
            if ($first === 0x01 && substr($Message->payload, 1) === "\x03") {
               continue;
            }

            return $Operation->fail('MySQL cancel requires fast authentication on the side channel.');
         }

         // @ KILL QUERY — advisory; the main operation resolves on its own socket
         fwrite($socket, $this->Encoder->encode(Encoder::QUERY, "KILL QUERY {$this->thread}"));
         $this->pull($socket, $Decoder, $Queue);

         $Operation->cancelled = true;

         // :
         return $Operation;
      }
      finally {
         fclose($socket);
      }
   }

   /**
    * Check whether this connection still has queued operations.
    */
   public function check (): bool
   {
      return $this->pipeline !== [];
   }

   /**
    * Pull one packet from a blocking side-channel socket.
    *
    * @param resource $socket
    * @param array<int,Message> $Queue
    */
   private function pull (mixed $socket, Decoder $Decoder, array &$Queue): null|Message
   {
      // ?: Packets decoded by a previous read
      if ($Queue !== []) {
         return array_shift($Queue);
      }

      // @@ Blocking read until one packet decodes or the stream ends
      while (true) {
         $bytes = @fread($socket, 8192);

         if ($bytes === false || ($bytes === '' && feof($socket))) {
            return null;
         }

         $Queue = $Decoder->decode($bytes);

         if ($Queue !== []) {
            return array_shift($Queue);
         }
      }
   }

   /**
    * Drain operations completed while reading queued responses.
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
    * Attach cached read/write readiness to an operation.
    */
   private function await (Operation $Operation, int $flag): Operation
   {
      $socket = $this->Connection->socket;

      if (is_resource($socket) === false) {
         return $this->abort($Operation, 'MySQL socket is not available.');
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
         $this->abort($Operation, 'MySQL socket is not available.');

         return false;
      }

      $written = @fwrite($socket, $Operation->write);

      if ($written === false) {
         $this->abort($Operation, 'MySQL socket write failed.');

         return false;
      }

      if ($written === 0) {
         if (feof($socket)) {
            $this->abort($Operation, 'MySQL socket closed during write.');

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
    * Read and apply server packets from the socket.
    */
   private function read (Operation $Operation): OperationStates
   {
      $socket = $this->Connection->socket;

      if (is_resource($socket) === false) {
         $this->abort($Operation, 'MySQL socket is not available.');

         return $Operation->state;
      }

      $bytes = @fread($socket, 8192);

      if ($bytes === false) {
         $this->abort($Operation, 'MySQL socket read failed.');

         return $Operation->state;
      }

      if ($bytes === '') {
         if (feof($socket)) {
            $this->abort($Operation, 'MySQL socket closed.');

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
            continue;
         }

         $this->apply($Active, $Message);

         if ($Active->finished) {
            if (($this->pipeline[0] ?? null) === $Active) {
               array_shift($this->pipeline);
               $this->completed[] = $Active;
            }

            $this->clear();
         }
      }

      return $Operation->state;
   }

   /**
    * Queue one operation into the request-response FIFO.
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
    * Reset the per-command result set state.
    */
   private function clear (): void
   {
      $this->expected = 0;
      $this->meta = [];
      $this->phase = '';
      $this->draining = false;
      $this->preparing = false;
      $this->definitions = 0;
      $this->binary = false;
   }

   /**
    * Abort the session after a transport failure.
    *
    * A dead socket can never deliver the responses the FIFO is waiting for:
    * every queued operation fails, the session state (statement ids, packet
    * buffer, pending closes) dies with the socket and the connection is
    * disconnected so the pool drops it instead of keeping it busy forever.
    */
   private function abort (Operation $Operation, string $error): Operation
   {
      // ! Session state — packets, statement ids and evictions die with the socket
      $this->clear();
      $this->closing = '';
      $this->statements = [];
      $this->Decoder = new Decoder;

      $Pipeline = $this->pipeline;
      $this->pipeline = [];

      // @@ Queued operations — completed[] hands the siblings to Pool::drain()
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

      // :
      return $Operation;
   }

   /**
    * Apply a decoded server packet to an operation.
    */
   private function apply (Operation $Operation, Message $Message): Operation
   {
      $state = $Operation->state;

      // # Handshake phases
      if ($state === OperationStates::Startup) {
         return $this->greet($Operation, $Message);
      }

      if ($state === OperationStates::Authenticating || $state === OperationStates::Password) {
         return $this->authenticate($Operation, $Message);
      }

      // # Result flow
      $payload = $Message->payload;
      $first = ord($payload[0] ?? "\0");

      // # COM_STMT_PREPARE response
      if ($this->preparing) {
         $this->preparing = false;

         if ($first === 0xFF) {
            return $this->fail($Operation, $payload);
         }

         $fields = $this->Decoder->read($payload, 'prepared');
         $statement = $fields['statement'] ?? 0;
         $statement = is_int($statement) ? $statement : 0;
         $columns = $fields['columns'] ?? 0;
         $columns = is_int($columns) ? $columns : 0;
         $parameters = $fields['parameters'] ?? 0;
         $parameters = is_int($parameters) ? $parameters : 0;

         // ? Cache full — evict the least recently used statement on the wire
         if ($this->SQLConfig->statements > 0 && count($this->statements) >= $this->SQLConfig->statements) {
            $evicted = (string) array_key_first($this->statements);
            $this->closing .= $this->Encoder->encode(Encoder::CLOSE, $this->statements[$evicted]['statement']);
            unset($this->statements[$evicted]);
         }

         $this->statements[$Operation->SQL] = [
            'statement' => $statement,
            'parameters' => $parameters,
            'columns' => $columns,
         ];

         // ! Parameter/column definition packets to consume (+EOFs pre-5.7.5)
         $eofs = ($this->capabilities & Capabilities::DEPRECATE_EOF)
            ? 0
            : ($parameters > 0 ? 1 : 0) + ($columns > 0 ? 1 : 0);
         $this->definitions = $parameters + $columns + $eofs;

         // ?: No definitions — the EXECUTE can go out immediately
         if ($this->definitions === 0) {
            return $this->execute($Operation);
         }

         $this->phase = 'definitions';

         return $Operation;
      }

      if ($this->phase === 'definitions') {
         $this->definitions--;

         // ?: Definitions consumed — re-queue the command as COM_STMT_EXECUTE
         if ($this->definitions <= 0) {
            $this->phase = '';

            return $this->execute($Operation);
         }

         return $Operation;
      }

      if ($this->phase === '') {
         if ($first === 0x00) {
            $fields = $this->Decoder->read($payload, 'ok');

            return $this->finish($Operation, $fields);
         }

         if ($first === 0xFF) {
            return $this->fail($Operation, $payload);
         }

         if ($first === 0xFB) {
            return $Operation->fail('MySQL LOCAL INFILE requests are not supported.');
         }

         // ! Result set header — column count
         $cursor = 0;
         $this->expected = (int) $this->Decoder->slice($payload, $cursor);
         $this->phase = 'columns';

         return $Operation;
      }

      if ($this->phase === 'columns') {
         $column = $this->Decoder->read($payload, 'column');
         $name = $column['name'] ?? '';
         $name = is_string($name) ? $name : '';
         $type = $column['type'] ?? 0;
         $type = is_int($type) ? $type : 0;
         $flags = $column['flags'] ?? 0;
         $flags = is_int($flags) ? $flags : 0;
         $this->meta[] = [
            'name' => $name,
            'type' => $type,
            'flags' => $flags,
         ];

         if ($this->draining === false) {
            $Operation->columns[] = $name;
            $Operation->types[] = $type;
         }

         if (count($this->meta) >= $this->expected) {
            $this->phase = ($this->capabilities & Capabilities::DEPRECATE_EOF) ? 'rows' : 'eof';
         }

         return $Operation;
      }

      if ($this->phase === 'eof') {
         // @ Column definition terminator (EOF) — rows follow.
         $this->phase = 'rows';

         return $Operation;
      }

      // # Rows
      if ($first === 0xFF) {
         return $this->fail($Operation, $payload);
      }

      $terminal = $first === 0xFE && (
         ($this->capabilities & Capabilities::DEPRECATE_EOF)
            ? strlen($payload) < Decoder::PAYLOAD_MAX
            : strlen($payload) < 9
      );

      if ($terminal) {
         $mode = ($this->capabilities & Capabilities::DEPRECATE_EOF) ? 'ok' : 'eof';
         $fields = $this->Decoder->read($payload, $mode);

         return $this->finish($Operation, $fields);
      }

      if ($this->draining === false) {
         $values = $this->Decoder->read($payload, $this->binary ? 'binary' : 'row', $this->meta);
         $row = [];

         foreach ($values as $index => $value) {
            $key = $Operation->columns[$index] ?? (string) $index;
            $column = $this->meta[$index] ?? [];
            $row[$key] = $this->cast($value, (int) ($column['type'] ?? 0), (int) ($column['flags'] ?? 0));
         }

         $Operation->rows[] = $row;
      }

      return $Operation;
   }

   /**
    * Re-queue a prepared command as COM_STMT_EXECUTE.
    */
   private function execute (Operation $Operation): Operation
   {
      $entry = $this->statements[$Operation->SQL] ?? null;

      // ?
      if ($entry === null) {
         return $Operation->fail('MySQL prepared statement metadata is missing.');
      }

      try {
         // ! Pending COM_STMT_CLOSE packets are prepended by the Querying flush.
         $Operation->write = $this->Encoder->encode(Encoder::EXECUTE, [
            'statement' => $entry['statement'],
            'parameters' => $Operation->parameters,
         ]);
      }
      catch (Throwable $Throwable) {
         return $Operation->fail($Throwable->getMessage());
      }

      $Operation->prepared = true;
      $Operation->state = OperationStates::Querying;

      // :
      return $Operation;
   }

   /**
    * Apply the server greeting: negotiate capabilities and TLS.
    */
   private function greet (Operation $Operation, Message $Message): Operation
   {
      // ? ERR packet instead of a greeting (server overloaded / host blocked)
      if (ord($Message->payload[0] ?? "\0") === 0xFF) {
         $Operation->quarantine = true;

         return $this->fail($Operation, $Message->payload);
      }

      try {
         $fields = $this->Decoder->read($Message->payload, 'greeting');
      }
      catch (Throwable $Throwable) {
         $Operation->quarantine = true;

         return $Operation->fail($Throwable->getMessage());
      }

      $server = $fields['capabilities'] ?? 0;
      $server = is_int($server) ? $server : 0;

      if (($server & Capabilities::PROTOCOL_41) === 0) {
         $Operation->quarantine = true;

         return $Operation->fail('MySQL server does not support protocol 4.1.');
      }

      // ! Server identity
      $thread = $fields['thread'] ?? 0;
      $version = $fields['version'] ?? '';
      $nonce = $fields['nonce'] ?? '';
      $plugin = $fields['plugin'] ?? '';
      $this->thread = is_int($thread) ? $thread : 0;
      $this->version = is_string($version) ? $version : '';
      $this->nonce = rtrim(is_string($nonce) ? $nonce : '', "\0");
      $this->plugin = is_string($plugin) && $plugin !== ''
         ? $plugin
         : Authentication::NATIVE;

      // ! Capability negotiation
      $desired = Capabilities::LONG_PASSWORD
         | Capabilities::LONG_FLAG
         | Capabilities::PROTOCOL_41
         | Capabilities::TRANSACTIONS
         | Capabilities::SECURE_CONNECTION
         | Capabilities::MULTI_RESULTS
         | Capabilities::PLUGIN_AUTH
         | Capabilities::PLUGIN_AUTH_LENENC
         | Capabilities::DEPRECATE_EOF;

      if ($this->Config->database !== '') {
         $desired |= Capabilities::CONNECT_WITH_DB;
      }

      $this->capabilities = $desired & $server;

      // # TLS
      $mode = $this->Config->secure['mode'];
      $tls = $mode !== DatabaseConfig::SECURE_DISABLE && ($server & Capabilities::SSL) !== 0;

      if ($tls === false && $mode !== DatabaseConfig::SECURE_DISABLE && $mode !== DatabaseConfig::SECURE_PREFER) {
         $Operation->quarantine = true;

         return $Operation->fail('MySQL server refused required TLS.');
      }

      if ($tls) {
         $this->capabilities |= Capabilities::SSL;
         $this->Connection->transition(ConnectionStates::SSLRequest);
         $Operation->write = $this->Encoder->encode(Encoder::SSL, [
            'capabilities' => $this->capabilities,
         ], $Message->sequence + 1);
         $Operation->state = OperationStates::SSLRequest;

         return $Operation;
      }

      // :
      return $this->respond($Operation, $Message->sequence + 1);
   }

   /**
    * Build the HandshakeResponse41 write for the negotiated plugin.
    */
   private function respond (Operation $Operation, int $sequence): Operation
   {
      try {
         $auth = $this->Authentication->scramble($this->plugin, $this->nonce);
      }
      catch (Throwable $Throwable) {
         $Operation->quarantine = true;

         return $Operation->fail($Throwable->getMessage());
      }

      $Operation->write = $this->Encoder->encode(Encoder::RESPONSE, [
         'capabilities' => $this->capabilities,
         'auth' => $auth,
         'plugin' => $this->plugin,
         'config' => $this->Config,
      ], $sequence);
      $Operation->state = OperationStates::Password;

      return $Operation;
   }

   /**
    * Apply an authentication-phase packet.
    */
   private function authenticate (Operation $Operation, Message $Message): Operation
   {
      $payload = $Message->payload;
      $first = ord($payload[0] ?? "\0");

      if ($first === 0x00) {
         $this->Authentication->authenticated = true;

         // @ Events — SQL connection authenticated (guarded: zero-alloc when no listeners)
         $Emitter = Emitter::$Instance;
         $Emitter->check(Events::Connected) && $Emitter->emit(Events::Connected, $this->Connection);

         $this->Connection->transition();
         $Operation->state = OperationStates::Querying;

         return $Operation;
      }

      if ($first === 0xFF) {
         return $this->fail($Operation, $payload);
      }

      // ? AuthSwitchRequest — restart the scramble with the requested plugin
      if ($first === 0xFE) {
         $cursor = 1;
         $stop = strlen($payload);
         $plugin = '';

         while ($cursor < $stop && $payload[$cursor] !== "\0") {
            $plugin .= $payload[$cursor];
            $cursor++;
         }

         $this->plugin = $plugin !== '' ? $plugin : Authentication::NATIVE;
         $this->nonce = rtrim(substr($payload, $cursor + 1), "\0");

         try {
            $auth = $this->Authentication->scramble($this->plugin, $this->nonce);
         }
         catch (Throwable $Throwable) {
            return $Operation->fail($Throwable->getMessage());
         }

         $Operation->write = $this->Encoder->encode(Encoder::AUTH, $auth, $Message->sequence + 1);
         $Operation->state = OperationStates::Password;

         return $Operation;
      }

      // ? AuthMoreData — caching_sha2_password continuation
      if ($first === 0x01) {
         $data = substr($payload, 1);

         // ?: Fast authentication success — the OK packet follows
         if ($data === "\x03") {
            return $Operation;
         }

         if ($data === "\x04") {
            // @ Full authentication — cleartext over TLS, pinned RSA key otherwise
            if ($this->encrypted) {
               $Operation->write = $this->Encoder->encode(Encoder::AUTH, "{$this->Config->password}\0", $Message->sequence + 1);
               $Operation->state = OperationStates::Password;

               return $Operation;
            }

            // ? Never trust a server-provided RSA key over plaintext — an
            //   active MITM could substitute its own key and decrypt the
            //   password. Only a locally pinned public key is accepted.
            $key = $this->Config->secure['key'];

            if ($key === '') {
               $Operation->quarantine = true;

               return $Operation->fail(
                  'MySQL caching_sha2_password full authentication over plaintext requires TLS or a pinned server public key (secure `key`).'
               );
            }

            try {
               $encrypted = $this->Authentication->encrypt($key, $this->nonce);
            }
            catch (Throwable $Throwable) {
               return $Operation->fail($Throwable->getMessage());
            }

            $Operation->write = $this->Encoder->encode(Encoder::AUTH, $encrypted, $Message->sequence + 1);
            $Operation->state = OperationStates::Password;

            return $Operation;
         }
      }

      return $Operation->fail('MySQL authentication received an unexpected packet.');
   }

   /**
    * Discard the operation statement when the cache is disabled.
    *
    * With `statements === 0` every command prepares its own server statement:
    * queue a COM_STMT_CLOSE for it as soon as the command completes so the
    * server does not accumulate prepared statements.
    */
   private function discard (Operation $Operation): void
   {
      // ?
      if ($Operation->prepared === false || $this->SQLConfig->statements > 0) {
         return;
      }

      $entry = $this->statements[$Operation->SQL] ?? null;

      if ($entry === null) {
         return;
      }

      $this->closing .= $this->Encoder->encode(Encoder::CLOSE, $entry['statement']);
      unset($this->statements[$Operation->SQL]);
   }

   /**
    * Finish the active command from a terminal OK/EOF packet.
    *
    * @param array<int|string,mixed> $fields
    */
   private function finish (Operation $Operation, array $fields): Operation
   {
      $status = $fields['status'] ?? 0;
      $status = is_int($status) ? $status : 0;

      // ? More result sets follow — keep the first, drain the rest
      if ($status & Capabilities::STATUS_MORE_RESULTS) {
         $this->expected = 0;
         $this->meta = [];
         $this->phase = '';
         $this->draining = true;

         return $Operation;
      }

      $affected = $fields['affected'] ?? 0;
      $affected = is_int($affected) ? $affected : 0;
      $inserted = $fields['inserted'] ?? 0;
      $inserted = is_int($inserted) ? $inserted : 0;

      // ! Command tag — mirror the PostgreSQL CommandComplete format
      $keyword = preg_match('/^\s*(\w+)/', $Operation->SQL, $matches) === 1
         ? strtoupper($matches[1])
         : '';
      $tag = match (true) {
         $Operation->columns !== [] => 'SELECT ' . count($Operation->rows),
         $keyword === 'INSERT' => "INSERT 0 {$affected}",
         $keyword === 'UPDATE' || $keyword === 'DELETE' || $keyword === 'REPLACE' => "{$keyword} {$affected}",
         default => $keyword
      };

      $Operation->status = $tag;
      $Operation->affected = $affected;

      $this->discard($Operation);
      $this->Connection->transition();

      // :
      return $Operation->resolve(new Result(
         $tag,
         $Operation->rows,
         $Operation->columns,
         $affected,
         $inserted
      ));
   }

   /**
    * Fail the active operation from an ERR packet.
    */
   private function fail (Operation $Operation, string $payload): Operation
   {
      $fields = $this->Decoder->read($payload, 'error');
      $code = $fields['code'] ?? 0;
      $code = is_int($code) ? $code : 0;
      $message = is_string($fields['message'] ?? null) ? $fields['message'] : '';

      // ? Command errors leave the session usable — keep the connection Ready.
      if ($this->Authentication->authenticated) {
         $this->discard($Operation);
         $this->Connection->transition();
      }

      return $Operation->fail("{$code}: {$message}");
   }

   /**
    * Cast one text-protocol value to its PHP type.
    */
   private function cast (mixed $value, int $type, int $flags): mixed
   {
      // ?
      if ($value === null || is_string($value) === false) {
         return $value;
      }

      switch ($type) {
         case Decoder::TYPE_TINY:
         case Decoder::TYPE_SHORT:
         case Decoder::TYPE_LONG:
         case Decoder::TYPE_INT24:
         case Decoder::TYPE_YEAR:
            // :
            return (int) $value;

         case Decoder::TYPE_LONGLONG:
            $integer = (int) $value;

            // ?: Unsigned values beyond PHP_INT_MAX stay strings
            return (string) $integer === $value ? $integer : $value;

         case Decoder::TYPE_FLOAT:
         case Decoder::TYPE_DOUBLE:
            // :
            return (float) $value;

         case Decoder::TYPE_DATE:
         case Decoder::TYPE_DATETIME:
         case Decoder::TYPE_TIMESTAMP:
            // :
            return $this->parse($value);

         default:
            // : DECIMAL/NEWDECIMAL, TIME (durations exceed 24h) and strings stay strings
            return $value;
      }
   }

   /**
    * Parse one temporal string into DateTimeImmutable.
    */
   private function parse (string $value): DateTimeImmutable|string
   {
      // ? Zero dates cannot be represented
      if ($value === '' || $value[0] === '0' && substr($value, 0, 10) === '0000-00-00') {
         return $value;
      }

      try {
         return new DateTimeImmutable($value);
      }
      catch (Throwable) {
         return $value;
      }
   }
}
