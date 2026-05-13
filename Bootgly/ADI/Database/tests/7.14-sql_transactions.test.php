<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Database\Operation\OperationStates;
use Bootgly\ADI\Databases\SQL;


return new Specification(
   description: 'Database: SQL transactions pin one pooled connection and support savepoints',
   test: function () {
      [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($server, false);

      $complete = static function (string $command): string {
         $command = "{$command}\0";
         $commandLength = pack('N', strlen($command) + 4);
         $readyLength = pack('N', 5);

         return "C{$commandLength}{$command}Z{$readyLength}I";
      };

      $Database = new SQL([
         'pool' => [
            'min' => 0,
            'max' => 1,
         ],
      ]);
      $Database->Connection->attach($client);

      $Transaction = $Database->begin();
      $Operation = $Transaction->Operation;

      yield assert(
         assertion: $Operation !== null && $Operation->lock,
         description: 'SQL begin creates a lock operation'
      );

      $Early = $Transaction->query('SELECT 0 AS early');

      yield assert(
         assertion: $Early->error === 'SQL transaction operation is still active.',
         description: 'Transaction rejects a new operation until BEGIN is awaited'
      );

      $Database->advance($Operation);
      $wire = fread($server, 8192);

      yield assert(
         assertion: str_contains($wire, "BEGIN\0"),
         description: 'Begin operation sends BEGIN on the wire'
      );

      fwrite($server, $complete('BEGIN'));
      $Database->advance($Operation);

      yield assert(
         assertion: $Operation->state === OperationStates::Finished
            && count($Database->Pool->busy) === 1
            && $Database->Pool->idle === [],
         description: 'Pool keeps the transaction connection locked after BEGIN'
      );

      $Outside = $Database->query('SELECT 1 AS outside');

      yield assert(
         assertion: $Outside->state === OperationStates::Pending,
         description: 'A normal query cannot borrow the locked transaction connection'
      );

      $Inside = $Transaction->query('SELECT 2 AS inside');

      yield assert(
         assertion: $Inside->Connection === $Operation->Connection,
         description: 'Transaction query is pinned to the BEGIN connection'
      );

      $Database->advance($Inside);
      $wire = fread($server, 8192);

      yield assert(
         assertion: str_contains($wire, "SELECT 2 AS inside\0"),
         description: 'Pinned transaction query writes through the same connection'
      );

      fwrite($server, $complete('SELECT 1'));
      $Database->advance($Inside);

      $Save = $Transaction->save();
      $Database->advance($Save);
      $wire = fread($server, 8192);

      yield assert(
         assertion: $Transaction->depth === 2 && str_contains($wire, "SAVEPOINT \"bootgly_0\"\0"),
         description: 'Savepoint increments transaction depth and uses a generated name'
      );

      fwrite($server, $complete('SAVEPOINT'));
      $Database->advance($Save);

      $Rollback = $Transaction->rollback();
      $Database->advance($Rollback);
      $wire = fread($server, 8192);

      yield assert(
         assertion: $Transaction->depth === 1 && str_contains($wire, "ROLLBACK TO SAVEPOINT \"bootgly_0\"\0"),
         description: 'Nested rollback targets the latest generated savepoint'
      );

      fwrite($server, $complete('ROLLBACK'));
      $Database->advance($Rollback);

      $Commit = $Transaction->commit();

      yield assert(
         assertion: $Commit->unlock,
         description: 'Top-level commit unlocks the transaction connection'
      );

      $Database->advance($Commit);
      $wire = fread($server, 8192);

      yield assert(
         assertion: str_contains($wire, "COMMIT\0"),
         description: 'Commit operation sends COMMIT on the wire'
      );

      fwrite($server, $complete('COMMIT'));
      $Database->advance($Commit);

      yield assert(
         assertion: $Commit->state === OperationStates::Finished && $Transaction->depth === 0,
         description: 'Commit finishes the transaction state'
      );

      fclose($server);
      $Database->Connection->disconnect();
   }
);
