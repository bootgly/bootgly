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
         description: 'A stand-in owed a reply keeps the connection out of the pool'
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

      foreach ($sockets as $socket) {
         fclose($socket);
      }

      $MySQLConnection->disconnect();
      $RedisConnection->disconnect();
   }
);
