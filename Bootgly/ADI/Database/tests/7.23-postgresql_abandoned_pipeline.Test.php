<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Operation\OperationStates;
use Bootgly\ADI\Databases\SQL;


return new Test(
   description: 'Database: PostgreSQL drains an abandoned pipeline slot without reviving it',
   test: function () {
      $connect = function (float $timeout = 30.0): array {
         [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         stream_set_blocking($client, false);
         stream_set_blocking($server, false);

         $Database = new SQL([
            'timeout' => $timeout,
            'pool' => [
               'min' => 0,
               'max' => 1,
            ],
         ]);
         $Database->Connection->attach($client);

         return [$Database, $server];
      };
      $message = fn (string $type, string $payload): string =>
         $type . pack('N', strlen($payload) + 4) . $payload;
      $describe = fn (string $name): string => pack('n', 1)
         . "{$name}\0" . pack('N', 0) . pack('n', 0) . pack('N', 23)
         . pack('n', 4) . pack('N', 0xFFFFFFFF) . pack('n', 0);
      $row = fn (string $value): string => pack('n', 1) . pack('N', strlen($value)) . $value;
      $result = fn (string $name, string $value, string $command): string =>
         $message('T', $describe($name))
         . $message('D', $row($value))
         . $message('C', "{$command}\0")
         . $message('Z', 'I');
      $expired = 'Database operation timed out after 1 seconds.';

      // # An abandoned slot drains while its sibling keeps its own answer
      [$Database, $server] = $connect();
      $First = $Database->query('SELECT 1 AS value');
      $Database->advance($First);
      fread($server, 8192);
      $Second = $Database->query('SELECT 2 AS value');
      $Database->advance($Second);
      fread($server, 8192);

      $Protocol = $First->Protocol;
      $First->fail($expired);
      $Protocol->abandon($First);

      fwrite($server, $result('value', '1', 'SELECT 1') . $result('value', '2', 'SELECT 1'));
      $Database->advance($Second);

      yield assert(
         assertion: $First->state === OperationStates::Failed
            && $First->error === $expired
            && $First->Result === null
            && $First->rows === []
            && $First->columns === []
            && $First->status === '',
         description: 'The abandoned operation is never revived by its own late messages'
      );

      yield assert(
         assertion: $Second->state === OperationStates::Finished
            && $Second->rows === [['value' => 2]],
         description: 'The pipelined sibling still resolves from its own messages'
      );

      yield assert(
         assertion: $Database->Pool->created === 1
            && count($Database->Pool->idle) === 1
            && $Database->Pool->busy === [],
         description: 'Draining the abandoned slot gives the connection back to the pool'
      );

      fclose($server);
      $Database->Connection->disconnect();

      // # The backend statement an abandoned batch parsed stays known
      [$Database, $server] = $connect();
      $First = $Database->query('SELECT $1::int AS value', [1]);
      $Database->advance($First);
      fread($server, 8192);
      $Second = $Database->query('SELECT 2 AS value');
      $Database->advance($Second);
      fread($server, 8192);

      $Protocol = $First->Protocol;
      $statement = $First->statement;
      $First->fail($expired);
      $Protocol->abandon($First);

      fwrite($server, $message('1', '') . $message('t', pack('n', 1) . pack('N', 23)));
      fwrite($server, $result('value', '1', 'SELECT 1') . $result('value', '2', 'SELECT 1'));
      $Database->advance($Second);

      yield assert(
         assertion: $statement !== '' && isset($Protocol->statements[$statement]),
         description: 'A statement the abandoned batch parsed is still cached for later binds'
      );

      yield assert(
         assertion: $First->prepared === false && $Second->rows === [['value' => 2]],
         description: 'Caching the statement writes nothing back into the abandoned operation'
      );

      fclose($server);
      $Database->Connection->disconnect();

      // # No reader left for the abandoned answer: the session is dropped
      [$Database, $server] = $connect();
      $Alone = $Database->query('SELECT 1 AS value');
      $Database->advance($Alone);
      fread($server, 8192);

      $Protocol = $Alone->Protocol;
      $Alone->fail($expired);
      $Protocol->abandon($Alone);

      yield assert(
         assertion: $Database->Connection->connected === false && $Protocol->check() === false,
         description: 'An abandoned answer nobody can read tears the session down'
      );

      yield assert(
         assertion: $Alone->error === $expired && $Alone->quarantine === false,
         description: 'Tearing the session down neither rewrites nor penalizes the abandoned operation'
      );

      fclose($server);

      // # An operation that never reached the wire is inert
      [$Database, $server] = $connect();
      $Head = $Database->query('SELECT 1 AS value');
      $Database->advance($Head);
      fread($server, 8192);

      $Loose = $Database->query('SELECT 3 AS value');
      $Protocol = $Loose->Protocol;
      $Loose->fail($expired);
      $Protocol->abandon($Loose);

      fwrite($server, $result('value', '1', 'SELECT 1'));
      $Database->advance($Head);

      yield assert(
         assertion: $Head->state === OperationStates::Finished
            && $Database->Connection->connected,
         description: 'Abandoning an operation the wire never carried changes nothing'
      );

      fclose($server);
      $Database->Connection->disconnect();

      // # The pool path: an expired operation gives its slot back
      [$Database, $server] = $connect(0.02);
      $Slow = $Database->query('SELECT 1 AS value');
      $Database->advance($Slow);
      fread($server, 8192);

      usleep(30_000);
      $Database->advance($Slow);

      yield assert(
         assertion: $Slow->finished && str_contains($Slow->error ?? '', 'timed out')
            && $Database->Pool->created === 0
            && $Database->Pool->busy === []
            && $Database->Connection->connected === false,
         description: 'An expired operation with no reader releases its pool slot'
      );

      fclose($server);
   }
);
