<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Operation as DatabaseOperation;
use Bootgly\ADI\Database\Config as KVConfig;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Database\Operation\OperationStates;
use Bootgly\ADI\Databases\KV\Drivers\Redis;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Config as SQLConfig;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL;
use Bootgly\ADI\Databases\SQL\Drivers\PostgreSQL;


return new Test(
   description: 'Database: the pool release gate holds a connection only while a reply is owed and reachable',
   test: function () {
      /**
       * Builds a pooled SQL database over a socketpair standing in for the backend.
       *
       * @return array{SQL, resource}
       */
      $connect = static function (int $max = 1): array {
         [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         stream_set_blocking($client, false);
         stream_set_blocking($server, false);

         $Database = new SQL([
            'timeout' => 30.0,
            'pool' => ['min' => 0, 'max' => $max],
         ]);
         $Database->Connection->attach($client);

         return [$Database, $server];
      };
      // ! Force one operation past its deadline while every sibling stays live.
      $elapse = static function (DatabaseOperation $Operation): void {
         (new ReflectionProperty(DatabaseOperation::class, 'deadline'))
            ->setValue($Operation, microtime(true) - 1.0);
      };

      $message = static fn (string $type, string $payload): string =>
         $type . pack('N', strlen($payload) + 4) . $payload;
      $failure = static fn (string $text): string =>
         $message('E', "SERROR\0C42601\0M{$text}\0\0");
      $describe = static fn (string $name): string => pack('n', 1)
         . "{$name}\0" . pack('N', 0) . pack('n', 0) . pack('N', 23)
         . pack('n', 4) . pack('N', 0xFFFFFFFF) . pack('n', 0);
      $row = static fn (string $value): string => pack('n', 1) . pack('N', strlen($value)) . $value;
      $result = static fn (string $name, string $value, string $command): string =>
         $message('T', $describe($name))
         . $message('D', $row($value))
         . $message('C', "{$command}\0")
         . $message('Z', 'I');

      // # A — a refused operation whose Sync lands in a later read
      //   TCP is free to split the backend's answer, and the read loop retires
      //   the head only on the message that terminates it. The head is finished
      //   meanwhile, owes its caller nothing, and must not hold the connection.
      [$Database, $server] = $connect();

      $Refused = $Database->query('SELECT bad syntax');
      $Database->advance($Refused);
      fread($server, 8192);
      fwrite($server, $failure('syntax error at or near "bad"'));
      $Database->advance($Refused);

      $Pool = $Database->Pool;

      yield assert(
         assertion: $Refused->state === OperationStates::Failed
            && $Refused->finished
            && $Pool->busy === []
            && count($Pool->idle) === 1,
         description: 'A finished head awaiting its terminating message gives the connection back'
      );

      // # A2 — the head is still the pipeline slot that absorbs the late Sync
      //   Returning the connection must not skip the message: the next operation
      //   is queued behind the head, whose own Sync retires it first.
      fwrite($server, $message('Z', 'I'));

      $Answered = $Database->query('SELECT 42 AS answer');
      $Database->advance($Answered);
      fread($server, 8192);
      fwrite($server, $result('answer', '42', 'SELECT 1'));
      $Database->advance($Answered);
      $Database->advance($Answered);

      yield assert(
         assertion: $Answered->state === OperationStates::Finished
            && $Answered->rows === [['answer' => 42]]
            && $Refused->rows === []
            && $Refused->error === 'syntax error at or near "bad"',
         description: 'The reused connection answers the next operation from its own messages'
      );

      fclose($server);
      $Database->Connection->disconnect();

      // # B — a transaction cannot co-locate, so the gate decides its capacity
      //   `lock: true` operations are never pipelined onto a busy connection:
      //   a connection parked behind the gate is capacity they can never reach.
      [$Database, $server] = $connect();

      $Refused = $Database->query('SELECT bad syntax');
      $Database->advance($Refused);
      fread($server, 8192);
      fwrite($server, $failure('syntax error'));
      $Database->advance($Refused);

      $Transaction = $Database->begin();
      $Begin = $Transaction->Operation;
      $Database->advance($Begin);

      yield assert(
         assertion: $Begin->error === null
            && $Begin->state !== OperationStates::Pending
            && $Database->Pool->pending === [],
         description: 'A transaction is given the connection a finished head no longer owes'
      );

      fclose($server);
      $Database->Connection->disconnect();

      // # C — a live pipelined sibling still gates the release
      //   The negative case: an unfinished entry IS owed a reply, and handing
      //   the connection out would give its messages to somebody else.
      [$Database, $server] = $connect();

      $Head = $Database->query('SELECT 1 AS value');
      $Database->advance($Head);
      fread($server, 8192);
      $Sibling = $Database->query('SELECT 2 AS value');
      $Database->advance($Sibling);
      fread($server, 8192);

      $elapse($Head);
      $Database->advance($Head);

      $Pool = $Database->Pool;

      yield assert(
         assertion: $Pool->created === 1
            && count($Pool->busy) === 1
            && $Pool->idle === []
            && $Database->Connection->Protocol?->check() === true,
         description: 'A live sibling behind a stand-in is owed a reply, so the connection stays out of the pool'
      );

      fwrite($server, $result('value', '1', 'SELECT 1') . $result('value', '2', 'SELECT 1'));
      $Database->advance($Sibling);

      yield assert(
         assertion: $Sibling->state === OperationStates::Finished
            && $Sibling->rows === [['value' => 2]]
            && count($Pool->idle) === 1,
         description: 'Draining the owed reply then returns the connection'
      );

      fclose($server);
      $Database->Connection->disconnect();

      // # C2 — a stand-in behind a live head owes nobody anything (POOL-14)
      //   The expired sibling's answer goes to a stand-in queued behind the
      //   head. The head stops reading the moment its own answer arrives, so
      //   counting the stand-in left the connection busy with nobody to pump
      //   it — for the worker's life, unless the pool saturated onto it. The
      //   gate ignores it instead: the connection goes idle, and the next
      //   operation handed it queues behind the stand-in and drains it.
      [$Database, $server] = $connect();

      $Head = $Database->query('SELECT 1 AS value');
      $Database->advance($Head);
      fread($server, 8192);
      $Sibling = $Database->query('SELECT 2 AS value');
      $Database->advance($Sibling);
      fread($server, 8192);

      $elapse($Sibling);
      $Database->advance($Sibling);

      $Pool = $Database->Pool;
      $owed = $Sibling->state === OperationStates::Failed
         && $Head->finished === false
         && $Database->Connection->Protocol?->check() === true
         && count($Pool->busy) === 1;

      // @ Only the head's answer arrives: the stand-in's is still owed.
      fwrite($server, $result('value', '1', 'SELECT 1'));
      $Database->advance($Head);

      yield assert(
         assertion: $owed
            && $Head->state === OperationStates::Finished
            && $Head->rows === [['value' => 1]]
            && $Database->Connection->Protocol?->check() === false
            && $Pool->busy === []
            && count($Pool->idle) === 1,
         description: 'A stand-in left behind a resolved head returns the connection to idle'
      );

      $Next = $Database->query('SELECT 3 AS value');
      $Database->advance($Next);
      $wire = (string) fread($server, 8192);
      fwrite($server, $result('value', '2', 'SELECT 1') . $result('value', '3', 'SELECT 1'));
      $Database->advance($Next);

      yield assert(
         assertion: str_contains($wire, 'SELECT 3 AS value')
            && $Next->state === OperationStates::Finished
            && $Next->rows === [['value' => 3]]
            && $Sibling->rows === []
            && count($Pool->idle) === 1,
         description: 'The next operation drains the stand-in, then reads only its own answer'
      );

      fclose($server);
      $Database->Connection->disconnect();

      // # C2b — a finished, unretired head is no reader (PG-13)
      //   The head failed on an ErrorResponse whose ReadyForQuery has not
      //   arrived, so it keeps its slot to absorb it — but nobody advances a
      //   finished operation again. A sibling behind it that the pool expires
      //   has no reader left: counting the head installed a stand-in nobody
      //   would pump, on a session the pool then lent on. The session goes.
      //   Withdrawn instead, the sibling keeps the session for the next caller
      //   to drain: that route needs no reader.
      foreach (['expired', 'withdrawn'] as $route) {
         [$Database, $server] = $connect();

         $Head = $Database->query('SELECT bad syntax');
         $Database->advance($Head);
         fread($server, 8192);
         fwrite($server, $failure('syntax error'));
         $Database->advance($Head);
         $Sibling = $Database->query('SELECT 2 AS value');
         $Database->advance($Sibling);
         fread($server, 8192);

         if ($route === 'expired') {
            $elapse($Sibling);
            $Database->advance($Sibling);
         }
         else {
            $Database->Pool->withdraw($Sibling);
         }

         $Pool = $Database->Pool;

         if ($route === 'expired') {
            yield assert(
               assertion: $Head->state === OperationStates::Failed
                  && $Sibling->state === OperationStates::Failed
                  && $Database->Connection->connected === false
                  && $Pool->created === 0
                  && $Pool->busy === []
                  && $Pool->idle === [],
               description: 'An expired sibling behind a finished head awaiting its ReadyForQuery has no reader: the session drops'
            );
         }
         else {
            $Next = $Database->query('SELECT 3 AS value');
            $Database->advance($Next);
            $wire = (string) fread($server, 8192);
            // @ The late ReadyForQuery, the withdrawn answer, the session reset
            //   written behind it, then the next operation's own answer.
            $unlocked = $message('C', "SELECT 1\0") . $message('C', "UNLISTEN\0") . $message('Z', 'I');
            fwrite($server, $message('Z', 'I') . $result('value', '2', 'SELECT 1') . $unlocked . $result('value', '3', 'SELECT 1'));
            $Database->advance($Next);

            yield assert(
               assertion: $Sibling->state === OperationStates::Failed
                  && str_contains($wire, 'SELECT 3 AS value')
                  && $Next->state === OperationStates::Finished
                  && $Next->rows === [['value' => 3]]
                  && count($Pool->idle) === 1,
               description: 'A sibling withdrawn behind a finished head keeps the session: the next operation absorbs the late ReadyForQuery and the stand-in, then reads its own answer'
            );
         }

         fclose($server);
         $Database->Connection->disconnect();
      }

      // # C3 — a batch still being flushed holds the connection
      //   It joins the pipeline only once its bytes are whole on the wire, so
      //   the FIFO alone says nothing is owed. Lending the connection then
      //   queues another caller's batch behind a stream this caller owns.
      [$client, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($peer, false);
      $Config = new SQLConfig(['secure' => ['mode' => 'disable']]);
      $PostgreSQLConnection = new Connection($Config);
      $PostgreSQLConnection->attach($client);
      $PostgreSQL = new PostgreSQL($Config, $PostgreSQLConnection);

      $Jammed = $PostgreSQL->query('SELECT 1 /* ' . str_repeat('x', 4 * 1024 * 1024) . ' */');
      $PostgreSQL->advance($Jammed);
      $flushing = $Jammed->state === OperationStates::Querying && $Jammed->write !== '';
      $writing = $PostgreSQL->check();

      $Jammed->fail('Database operation timed out after 1 seconds.');

      yield assert(
         assertion: $flushing && $writing === true && $PostgreSQL->check() === false,
         description: 'PostgreSQL owes the connection to a batch mid-flush, and nothing once it is finished'
      );

      fclose($peer);
      $PostgreSQLConnection->disconnect();

      // # D — a socket that can never deliver must not be held by the gate
      //   Everything that frees the slot — the reservation, the busy entry,
      //   drop() and promote() — lives below it, so gating a dead connection
      //   counts it against `max` for the pool's life.
      [$Database, $server] = $connect();

      $Head = $Database->query('SELECT 1 AS value');
      $Database->advance($Head);
      fread($server, 8192);
      $Sibling = $Database->query('SELECT 2 AS value');
      $Database->advance($Sibling);
      fread($server, 8192);

      fclose($server);
      $elapse($Head);
      $Database->advance($Head);

      $Pool = $Database->Pool;
      $held = $Pool->created;

      $Database->Connection->disconnect();
      $Pool->release($Sibling);

      yield assert(
         assertion: $held === 1
            && $Pool->created === 0
            && $Pool->busy === []
            && $Pool->idle === [],
         description: 'A dead connection is dropped even while the driver still holds its FIFO'
      );

      $Database->Connection->disconnect();

      // # E — every driver answers the gate the same way
      //   check() is a contract on Database\Driver, so its predicate cannot mean
      //   "has entries" for one driver and "owes a reply" for another. Each read
      //   loop retires its head on a different message — a Sync, a protocol sync
      //   point, the reply itself — and each can hold a finished one meanwhile.
      $sockets = [];

      // ! MySQL — a queued operation the pool failed from outside.
      [$client, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      $sockets[] = $peer;
      stream_set_blocking($client, false);
      stream_set_blocking($peer, false);
      $Config = new SQLConfig(['driver' => 'mysql', 'secure' => ['mode' => 'disable']]);
      $MySQLConnection = new Connection($Config);
      $MySQLConnection->attach($client);
      $MySQL = new MySQL($Config, $MySQLConnection);

      $Queued = $MySQL->query('SELECT 1');
      $MySQL->advance($Queued);
      fread($peer, 8192);
      $Queued->fail('Database operation timed out after 1 seconds.');

      yield assert(
         assertion: $MySQL->check() === false,
         description: 'MySQL owes nothing once its only queued operation is finished'
      );

      // ! MySQL — a stand-in head draining an abandoned command.
      [$client, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      $sockets[] = $peer;
      stream_set_blocking($client, false);
      stream_set_blocking($peer, false);
      $StandConnection = new Connection($Config);
      $StandConnection->attach($client);
      $Stand = new MySQL($Config, $StandConnection);

      $Head = $Stand->query('SELECT 1');
      $Sibling = $Stand->query('SELECT 2');
      $Stand->advance($Head);
      $Stand->advance($Sibling);
      fread($peer, 8192);
      $Head->fail('Database operation timed out after 1 seconds.');
      $Stand->abandon($Head);

      $owed = $Stand->check();
      $Sibling->fail('Database operation timed out after 1 seconds.');

      yield assert(
         assertion: $owed === true
            && $StandConnection->connected
            && $Stand->check() === false,
         description: 'MySQL owes a reply to a live sibling behind a stand-in head, and nothing to the stand-in alone'
      );

      // ! Redis — the same shape, with a live sibling behind the failed head.
      [$client, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      $sockets[] = $peer;
      stream_set_blocking($client, false);
      stream_set_blocking($peer, false);
      $Config = new KVConfig(['driver' => 'redis', 'secure' => ['mode' => 'disable']]);
      $RedisConnection = new Connection($Config);
      $RedisConnection->attach($client);
      $Redis = new Redis($Config, $RedisConnection);

      $Head = $Redis->command('GET', ['tenant-a']);
      $Sibling = $Redis->command('GET', ['tenant-b']);
      $Redis->advance($Head);
      $Redis->advance($Sibling);
      fread($peer, 8192);
      $Head->fail('Database operation timed out after 1 seconds.');

      $owed = $Redis->check();
      $Sibling->fail('Database operation timed out after 1 seconds.');

      yield assert(
         assertion: $owed === true && $Redis->check() === false,
         description: 'Redis owes a reply while a sibling is live, and nothing once it is not'
      );

      // ! Redis — a stand-in left behind a head that has since resolved.
      [$client, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      $sockets[] = $peer;
      stream_set_blocking($client, false);
      stream_set_blocking($peer, false);
      $StandingConnection = new Connection($Config);
      $StandingConnection->attach($client);
      $Standing = new Redis($Config, $StandingConnection);

      $Head = $Standing->command('GET', ['tenant-a']);
      $Sibling = $Standing->command('GET', ['tenant-b']);
      $Standing->advance($Head);
      $Standing->advance($Sibling);
      fread($peer, 8192);
      $Sibling->fail('Database operation timed out after 1 seconds.');
      $Standing->abandon($Sibling);

      // @ Only the head's reply arrives: the stand-in's is still owed.
      fwrite($peer, "\$1\r\na\r\n");
      $Standing->advance($Head);

      yield assert(
         assertion: $Head->response === 'a'
            && $StandingConnection->connected
            && $Standing->check() === false,
         description: 'Redis owes nothing to a stand-in left alone in its pipeline'
      );

      // # F — a finished entry is no reader, for every driver
      //   An operation finished from outside stays at its slot until the
      //   message that terminates it arrives, and nobody advances it again.
      //   An abandoned head with only such an entry behind it has no reader
      //   left to pump its answer, so the session drops instead of keeping a
      //   stand-in nobody drains.

      // ! MySQL — the sibling behind the head was failed from outside.
      [$client, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      $sockets[] = $peer;
      stream_set_blocking($client, false);
      stream_set_blocking($peer, false);
      $Config = new SQLConfig(['driver' => 'mysql', 'secure' => ['mode' => 'disable']]);
      $ReaderlessConnection = new Connection($Config);
      $ReaderlessConnection->attach($client);
      $Readerless = new MySQL($Config, $ReaderlessConnection);

      $Head = $Readerless->query('SELECT 1');
      $Sibling = $Readerless->query('SELECT 2');
      $Readerless->advance($Head);
      $Readerless->advance($Sibling);
      fread($peer, 8192);
      $Sibling->fail('Database operation timed out after 1 seconds.');
      $Head->fail('Database operation timed out after 1 seconds.');
      $Readerless->abandon($Head);

      yield assert(
         assertion: $ReaderlessConnection->connected === false
            && $Readerless->check() === false,
         description: 'MySQL drops an abandoned head whose only sibling is already finished'
      );

      // ! Redis — the same shape.
      [$client, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      $sockets[] = $peer;
      stream_set_blocking($client, false);
      stream_set_blocking($peer, false);
      $Config = new KVConfig(['driver' => 'redis', 'secure' => ['mode' => 'disable']]);
      $UnreadConnection = new Connection($Config);
      $UnreadConnection->attach($client);
      $Unread = new Redis($Config, $UnreadConnection);

      $Head = $Unread->command('GET', ['tenant-a']);
      $Sibling = $Unread->command('GET', ['tenant-b']);
      $Unread->advance($Head);
      $Unread->advance($Sibling);
      fread($peer, 8192);
      $Sibling->fail('Database operation timed out after 1 seconds.');
      $Head->fail('Database operation timed out after 1 seconds.');
      $Unread->abandon($Head);

      yield assert(
         assertion: $UnreadConnection->connected === false
            && $Unread->check() === false,
         description: 'Redis drops an abandoned head whose only sibling is already finished'
      );

      foreach ($sockets as $socket) {
         fclose($socket);
      }

      $MySQLConnection->disconnect();
      $StandConnection->disconnect();
      $RedisConnection->disconnect();
      $StandingConnection->disconnect();
      $ReaderlessConnection->disconnect();
      $UnreadConnection->disconnect();
   }
);
