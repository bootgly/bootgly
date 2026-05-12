<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Database;
use Bootgly\ADI\Database\OperationStates;


return new Specification(
   description: 'Database: operation timeout fails active and pending operations',
   test: function () {
      $Database = new Database([
         'timeout' => 0.001,
      ]);
      $Operation = $Database->query('SELECT 1 AS value');

      usleep(2_000);
      $Operation = $Database->advance($Operation);

      yield assert(
         assertion: $Operation->finished && $Operation->state === OperationStates::Failed && str_contains($Operation->error ?? '', 'timed out'),
         description: 'Active operation fails when its deadline expires'
      );

      [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($server, false);

      $Pool = new Database([
         'timeout' => 0.001,
         'pool' => [
            'min' => 0,
            'max' => 1,
         ],
      ]);
      $Pool->Connection->attach($client);

      $First = $Pool->query('SELECT 1 AS value');
      $Second = $Pool->query('SELECT 2 AS value');

      usleep(2_000);
      $Second = $Pool->advance($Second);

      yield assert(
         assertion: $First->finished === false && $Second->finished && $Second->state === OperationStates::Failed,
         description: 'Pending operation fails independently when its waiter deadline expires'
      );

      yield assert(
         assertion: count($Pool->Pool->pending) === 0,
         description: 'Timed-out pending operation is removed from the pool queue'
      );

      fclose($server);
      $Pool->Connection->disconnect();
   }
);
