<?php

use Bootgly\ACI\Events\Scheduler;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Database\Pool;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Locks;
use Bootgly\ADI\Databases\SQL\Builder\Query;
use Bootgly\ADI\Databases\SQL\Config as SQLConfig;
use Bootgly\ADI\Databases\SQL\Normalized;
use Bootgly\ADI\Databases\SQL\Operation as SQLOperation;


$Table = new class implements Stringable {
   public function __toString (): string
   {
      return 'users';
   }
};
$Column = new class implements Stringable {
   public function __toString (): string
   {
      return 'id';
   }
};


return new Specification(
   description: 'Database: SQL routes safe reads to replicas and writes to primary',
   test: function () use ($Table, $Column) {
      $Database = new SQL([
         'pool' => [
            'min' => 0,
            'max' => 0,
         ],
         'routing' => [
            'sticky' => 0.0,
         ],
         'replicas' => [
            [
               'host' => 'replica-a.local',
               'statements' => 12,
               'unknown' => true,
               'pool' => [
                  'min' => 0,
                  'max' => 0,
               ],
            ],
            [
               'host' => 'replica-b.local',
               'pool' => [
                  'min' => 0,
                  'max' => 0,
               ],
            ],
         ],
      ]);

      yield assert(
         assertion: count($Database->ReplicaPools) === 2
            && $Database->ReplicaPools[0] instanceof Pool
            && $Database->ReplicaPools[0]->Config->host === 'replica-a.local'
            && $Database->ReplicaPools[0]->Config instanceof SQLConfig
            && $Database->ReplicaPools[0]->Config->statements === 12
            && array_key_exists('unknown', $Database->SQLConfig->replicas[0]) === false
            && $Database->ReplicaPools[1]->Config->host === 'replica-b.local',
         description: 'SQL facade builds one pool per configured replica'
      );

      $First = $Database->query('SELECT 1 AS value');
      $Second = $Database->query(new Query('SELECT 2 AS value', [], reading: true));

      yield assert(
         assertion: $First->Pool === $Database->ReplicaPools[0]
            && $Second->Pool === $Database->ReplicaPools[1],
         description: 'Safe reads are distributed through replicas round-robin'
      );

      $Database->Pool->advance($First);

      yield assert(
         assertion: $Database->Pool->pending === [] && count($Database->ReplicaPools[0]->pending) === 1,
         description: 'Primary pool delegates replica operations to their assigned pool'
      );

      $Write = $Database->query('INSERT INTO users (name) VALUES ($1)', ['Ada']);
      $DDL = $Database->query('CREATE TABLE users (id int)');

      yield assert(
         assertion: $Write->Pool === $Database->Pool && $DDL->Pool === $Database->Pool,
         description: 'Writes and DDL are routed to the primary pool'
      );

      $Locked = $Database->query(
         $Database->table($Table)->select($Column)->lock(Locks::Update)
      );

      yield assert(
         assertion: $Locked->Pool === $Database->Pool,
         description: 'Locking SELECT builders are routed to the primary pool'
      );

      $Sticky = new SQL([
         'pool' => [
            'min' => 0,
            'max' => 0,
         ],
         'routing' => [
            'sticky' => 30.0,
         ],
         'replicas' => [
            [
               'host' => 'replica-c.local',
               'pool' => [
                  'min' => 0,
                  'max' => 0,
               ],
            ],
         ],
      ]);

      $Sticky->query('UPDATE users SET name = $1 WHERE id = $2', ['Ada', 1]);
      $AfterWrite = $Sticky->query('SELECT 1 AS value');

      yield assert(
         assertion: $AfterWrite->Pool === $Sticky->Pool,
         description: 'Read-after-write consistency window keeps same-scope reads on primary'
      );

      $Scoped = new SQL([
         'pool' => [
            'min' => 0,
            'max' => 0,
         ],
         'routing' => [
            'sticky' => 30.0,
         ],
         'replicas' => [
            [
               'host' => 'replica-scope.local',
               'pool' => [
                  'min' => 0,
                  'max' => 0,
               ],
            ],
         ],
      ]);
      $ScopeA = new stdClass;
      $ScopeB = new stdClass;
      $Scoped->query('UPDATE users SET name = $1 WHERE id = $2', ['Ada', 1], $ScopeA);
      $ScopeARead = $Scoped->query('SELECT 1 AS value', [], $ScopeA);
      $ScopeBRead = $Scoped->query('SELECT 2 AS value', [], $ScopeB);

      yield assert(
         assertion: $ScopeARead->Pool === $Scoped->Pool
            && $ScopeBRead->Pool === $Scoped->ReplicaPools[0],
         description: 'Read-after-write stickiness is isolated by logical scope'
      );

      $Fibered = new SQL([
         'pool' => [
            'min' => 0,
            'max' => 0,
         ],
         'routing' => [
            'sticky' => 30.0,
         ],
         'replicas' => [
            [
               'host' => 'replica-fiber.local',
               'pool' => [
                  'min' => 0,
                  'max' => 0,
               ],
            ],
         ],
      ]);
      $WriterRead = null;
      $ReaderRead = null;
      $Writer = new Fiber(function () use ($Fibered, &$WriterRead): void {
         $Fibered->query('UPDATE users SET name = $1 WHERE id = $2', ['Ada', 1]);
         $WriterRead = $Fibered->query('SELECT 1 AS value');
      });
      $Reader = new Fiber(function () use ($Fibered, &$ReaderRead): void {
         $ReaderRead = $Fibered->query('SELECT 2 AS value');
      });
      $Writer->start();
      $Reader->start();

      yield assert(
         assertion: $WriterRead instanceof SQLOperation
            && $ReaderRead instanceof SQLOperation
            && $WriterRead->Pool === $Fibered->Pool
            && $ReaderRead->Pool === $Fibered->ReplicaPools[0],
         description: 'Fiber-local stickiness does not pin sibling request reads to primary'
      );

      $Fallback = new SQLOperation(null, 'SELECT 1');
      $Fallback->Pool = $Database->ReplicaPools[0];
      $Fallback->FallbackPool = $Database->Pool;
      $Fallback->quarantine = true;
      $Fallback->fail('Replica connection failed.');
      $Database->ReplicaPools[0]->advance($Fallback);

      yield assert(
         assertion: $Fallback->fallback
            && $Fallback->Pool === $Database->Pool
            && $Fallback->error === null
            && count($Database->Pool->pending) === 1
            && $Database->ReplicaPools[0]->healthy
            && $Database->ReplicaPools[0]->failures === 1,
         description: 'First failed replica read retries without opening the breaker'
      );

      $SecondFallback = new SQLOperation(null, 'SELECT 1');
      $SecondFallback->Pool = $Database->ReplicaPools[0];
      $SecondFallback->FallbackPool = $Database->Pool;
      $SecondFallback->quarantine = true;
      $SecondFallback->fail('Replica connection failed again.');
      $Database->ReplicaPools[0]->advance($SecondFallback);

      yield assert(
         assertion: $SecondFallback->fallback
            && $SecondFallback->Pool === $Database->Pool
            && $SecondFallback->error === null
            && count($Database->Pool->pending) === 2
            && $Database->ReplicaPools[0]->healthy === false
            && $Database->ReplicaPools[0]->failures === Pool::DEFAULT_FAILURES,
         description: 'Replica breaker opens after the configured failure threshold'
      );

      $AfterQuarantine = $Database->query('SELECT 2 AS value');
      $Database->ReplicaPools[0]->recover();
      $AfterRecovery = $Database->query('SELECT 3 AS value');

      yield assert(
         assertion: $AfterQuarantine->Pool === $Database->ReplicaPools[1]
            && $AfterRecovery->Pool === $Database->ReplicaPools[0],
         description: 'Replica routing skips quarantined pools and reuses them after recovery'
      );

      $Expired = new SQLOperation(null, 'SELECT 4 AS value');
      $Expired->Pool = $Database->ReplicaPools[0];
      $Expired->FallbackPool = $Database->Pool;
      $Deadline = new ReflectionProperty(SQLOperation::class, 'deadline');
      $Deadline->setValue($Expired, 1.0);
      $Database->ReplicaPools[0]->advance($Expired);

      yield assert(
         assertion: $Expired->fallback
            && $Expired->Pool === $Database->Pool
            && $Database->ReplicaPools[0]->healthy
            && $Database->ReplicaPools[0]->failures === 0,
         description: 'Replica query timeout falls back without quarantining the pool'
      );

      $start = microtime(true);
      $Database->ReplicaPools[0]->penalize(Pool::DEFAULT_RETRY, Pool::DEFAULT_FAILURES, Pool::DEFAULT_JITTER);
      $firstRetry = $Database->ReplicaPools[0]->retry;
      $firstFailures = $Database->ReplicaPools[0]->failures;
      $Database->ReplicaPools[0]->penalize(Pool::DEFAULT_RETRY, Pool::DEFAULT_FAILURES, Pool::DEFAULT_JITTER);

      yield assert(
         assertion: $firstFailures === 1
            && $firstRetry === 0.0
            && $Database->ReplicaPools[0]->failures === Pool::DEFAULT_FAILURES
            && $Database->ReplicaPools[0]->retry > $start + Pool::DEFAULT_RETRY
            && $Database->ReplicaPools[0]->healthy === false,
         description: 'Replica breaker uses failure threshold and jittered retry deadlines'
      );

      $Database->ReplicaPools[0]->recover();

      $Server = stream_socket_server('tcp://127.0.0.1:0');
      $address = is_resource($Server) ? stream_socket_get_name($Server, false) : '';
      $separator = is_string($address) ? strrchr($address, ':') : false;
      $port = $separator === false ? 1 : (int) substr($separator, 1);

      if (is_resource($Server)) {
         fclose($Server);
      }

      $Broken = new SQL([
         'timeout' => 0.01,
         'secure' => [
            'mode' => SQLConfig::SECURE_DISABLE,
         ],
         'pool' => [
            'min' => 0,
            'max' => 0,
         ],
         'routing' => [
            'sticky' => 0.0,
         ],
         'replicas' => [
            [
               'host' => '127.0.0.1',
               'port' => $port,
               'pool' => [
                  'min' => 0,
                  'max' => 1,
               ],
            ],
         ],
      ]);
      $BrokenRead = $Broken->query('SELECT 7 AS value');

      for ($attempt = 0; $attempt < 8 && $BrokenRead->fallback === false; $attempt++) {
         $Broken->advance($BrokenRead);
         $Readiness = $BrokenRead->Readiness;

         if ($Readiness === null) {
            continue;
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

         stream_select($read, $write, $except, 0, 10000);
      }

      $BrokenAgain = $Broken->query('SELECT 8 AS value');

      for ($attempt = 0; $attempt < 8 && $BrokenAgain->fallback === false; $attempt++) {
         $Broken->advance($BrokenAgain);
         $Readiness = $BrokenAgain->Readiness;

         if ($Readiness === null) {
            continue;
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

         stream_select($read, $write, $except, 0, 10000);
      }

      yield assert(
         assertion: $BrokenRead->fallback
            && $BrokenAgain->fallback
            && $BrokenRead->Pool === $Broken->Pool
            && $BrokenAgain->Pool === $Broken->Pool
            && $BrokenRead->error === null
            && $BrokenAgain->error === null
            && $Broken->ReplicaPools[0]->healthy === false
            && $Broken->ReplicaPools[0]->failures === Pool::DEFAULT_FAILURES,
         description: 'Replica connect failure triggers fallback and opens breaker after threshold through the real async path'
      );

      [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($server, false);

      $IO = new SQL([
         'pool' => [
            'min' => 0,
            'max' => 0,
         ],
         'routing' => [
            'sticky' => 0.0,
         ],
         'replicas' => [
            [
               'host' => 'replica-io.local',
               'pool' => [
                  'min' => 0,
                  'max' => 1,
               ],
            ],
         ],
      ]);
      $IO->ReplicaPools[0]->Connection->attach($client);
      $Read = $IO->query('SELECT 5 AS value');
      $IO->advance($Read);

      $queryLength = pack('N', strlen('SELECT 5 AS value') + 5);
      $queryExpected = "Q{$queryLength}SELECT 5 AS value\0";

      yield assert(
         assertion: $Read->Pool === $IO->ReplicaPools[0]
            && fread($server, 8192) === $queryExpected
            && $IO->Pool->busy === [],
         description: 'Replica-routed read writes through the replica socket path'
      );

      fclose($server);
      $IO->ReplicaPools[0]->Connection->disconnect();

      yield assert(
         assertion: (new Normalized('SELECT 1'))->reading
            && (new Normalized('SHOW server_version'))->reading
            && (new Normalized('EXPLAIN SELECT 1'))->reading
            && (new Normalized('EXPLAIN ANALYZE SELECT 1'))->reading === false
            && (new Normalized('WITH rows AS (SELECT 1) SELECT * FROM rows'))->reading
            && (new Normalized('WITH deleted AS (DELETE FROM users RETURNING id) SELECT * FROM deleted'))->reading === false,
         description: 'Raw SQL classification only marks clearly safe reads as replica-readable'
      );
   }
);
