<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
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
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_numeric;
use function is_resource;
use function is_scalar;
use function is_string;
use function preg_match;
use function sha1;
use function str_starts_with;
use function stream_socket_client;
use function strlen;
use function strtolower;
use function substr;
use DateTimeImmutable;
use Throwable;

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
   /** @var array<string,string> */
   private array $names = [];
   /** @var array<string,true> */
   private array $preparing = [];
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
            $Operation->write = $Encoder->query($Operation->sql);

            return $Operation;
         }

         $statement = $this->names[$Operation->sql] ?? '';

         if ($statement === '') {
            $hash = sha1($Operation->sql);
            $statement = "bootgly_{$hash}";
            $this->names[$Operation->sql] = $statement;
         }

         $Operation->statement = $statement;
         $Operation->portal = '';
         $cached = $this->statements[$Operation->statement] ?? false;
         $preparing = isset($this->preparing[$Operation->statement]);
         $Operation->prepared = $cached !== false || $preparing;
         $types = [];
         $index = 0;

         foreach ($Operation->parameters as $parameter) {
            $types[] = $this->infer($parameter, $Operation, $index);
            $index++;
         }

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
            if ($cached !== false) {
               $this->evict($Operation->statement);
               $this->cache($Operation->statement, $cached);
            }

            $Operation->write = "{$bind}{$describe}{$execute}{$sync}";

            return $Operation;
         }

         $close = '';

         if ($this->SQLConfig->statements > 0 && count($this->statements) >= $this->SQLConfig->statements) {
            $evicted = array_key_first($this->statements);
            $this->evict($evicted);
            $close = $Encoder->encode(Encoder::CLOSE, [
               'type' => 'S',
               'name' => $evicted,
            ]);
         }

         $parse = $Encoder->parse([
            'statement' => $Operation->statement,
            'sql' => $Operation->sql,
            'types' => $types,
         ]);
         $describeStatement = $Encoder->describe([
            'type' => 'S',
            'name' => $Operation->statement,
         ]);
         $Operation->write = "{$close}{$parse}{$describeStatement}{$bind}{$describe}{$execute}{$sync}";

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

      if ($Operation->state === OperationStates::Queued) {
         if ($this->Connection->connected === false || is_resource($this->Connection->socket) === false) {
            $Operation->state = OperationStates::Connecting;

            try {
               return $Operation->await($this->Connection->connect($Operation->deadline));
            }
            catch (Throwable $Throwable) {
               return $Operation->fail($Throwable->getMessage());
            }
         }

         $Operation->state = OperationStates::Querying;
      }

      if ($Operation->state === OperationStates::Connecting) {
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
            $this->Connection->transition(ConnectionStates::Startup);
            $this->Authentication->authenticated = false;
            $Operation->write = $this->Encoder->encode(Encoder::STARTUP, $this->Config);
            $Operation->state = OperationStates::Startup;

            return $this->advance($Operation);
         }

         if ($encrypted === null) {
            return $this->await($Operation, Scheduler::SCHEDULE_WRITE);
         }

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
         if ($Operation->write === '') {
            $this->prepare($Operation);
            $Operation->state = OperationStates::Querying;
         }

         if ($this->flush($Operation) === false) {
            return $Operation;
         }

         if ($Operation->statement !== '' && $Operation->prepared === false) {
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
    * Check whether this connection still has pipelined operations.
    */
   public function check (): bool
   {
      return $this->pipeline !== [];
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
    * Cache prepared statement metadata.
    *
    * @param bool|array<int,int> $metadata
    */
   public function cache (string $statement, bool|array $metadata = true): self
   {
      if ($statement === '') {
         return $this;
      }

      unset($this->preparing[$statement]);
      $this->statements[$statement] = $metadata;

      return $this;
   }

   /**
    * Evict prepared statement metadata.
    */
   public function evict (string $statement): self
   {
      unset($this->preparing[$statement]);
      unset($this->statements[$statement]);

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
         $Operation->fail('PostgreSQL socket is not available.');

         return $Operation;
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
         $Operation->fail('PostgreSQL socket is not available.');

         return false;
      }

      $written = @fwrite($socket, $Operation->write);

      if ($written === false) {
         $Operation->fail('PostgreSQL socket write failed.');

         return false;
      }

      if ($written === 0) {
         if (feof($socket)) {
            $Operation->fail('PostgreSQL socket closed during write.');

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
    * Read SSLRequest response and transition TLS mode.
    */
   private function secure (Operation $Operation): Operation
   {
      $socket = $this->Connection->socket;

      if (is_resource($socket) === false) {
         $Operation->fail('PostgreSQL socket is not available.');

         return $Operation;
      }

      $response = @fread($socket, 1);

      if ($response === false) {
         $Operation->fail('PostgreSQL SSL response read failed.');

         return $Operation;
      }

      if ($response === '') {
         if (feof($socket)) {
            $Operation->fail('PostgreSQL socket closed during SSL negotiation.');

            return $Operation;
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
         $Operation->fail('PostgreSQL socket is not available.');

         return $Operation->state;
      }

      $bytes = @fread($socket, 8192);

      if ($bytes === false) {
         $Operation->fail('PostgreSQL socket read failed.');

         return $Operation->state;
      }

      if ($bytes === '') {
         if (feof($socket)) {
            $Operation->fail('PostgreSQL socket closed.');

            return $Operation->state;
         }

         return $Operation->state;
      }

      try {
         $Messages = $this->Decoder->decode($bytes);
      }
      catch (Throwable $Throwable) {
         $Operation->fail($Throwable->getMessage());

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
            $this->completed[] = $Active;
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
         $parts = explode(' ', $command);
         $last = $parts[count($parts) - 1] ?? '0';
         $Operation->affected = is_numeric($last) ? (int) $last : 0;

         return $Operation;
      }

      if ($type === 'Z') {
         if ($Operation->state === OperationStates::Failed) {
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

         return $Operation;
      }

      // @ Extended query no-ops — emitted by Bind / NoData / PortalSuspended.
      if ($type === '2' || $type === 'n' || $type === 's') {
         return $Operation;
      }

      if ($type === '1') {
         if ($Operation->statement !== '' && $this->SQLConfig->statements > 0) {
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

         if ($Operation->statement !== '' && $this->SQLConfig->statements > 0) {
            $this->cache($Operation->statement, $Operation->parameterTypes);
         }

         return $Operation;
      }

      if ($type === 'E') {
         $message = $Message->fields['message'] ?? 'PostgreSQL error.';

         if ($Operation->statement !== '') {
            $this->evict($Operation->statement);
            $Operation->prepared = false;
         }

         if (is_scalar($message) === false) {
            $message = 'PostgreSQL error.';
         }

         return $Operation->fail((string) $message);
      }

      if ($type === 'R') {
         $code = $Message->fields['code'] ?? -1;

         if (is_int($code) === false) {
            $code = -1;
         }

         if ($code === 0) {
            $this->Authentication->authenticated = true;

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
         return 23;
      }

      if (is_bool($parameter)) {
         return 16;
      }

      if (is_float($parameter)) {
         return 701;
      }

      if (is_string($parameter)) {
         return 25;
      }

      $position = $index + 1;
      $pattern = '/\\$' . $position . '\\s*::\\s*([a-zA-Z_][a-zA-Z0-9_]*)(?:\\s+([a-zA-Z_][a-zA-Z0-9_]*))?/i';

      if (preg_match($pattern, $Operation->sql, $matches) !== 1) {
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
