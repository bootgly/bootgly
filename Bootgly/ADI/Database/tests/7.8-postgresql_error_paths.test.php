<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Database;
use Bootgly\ADI\Database\OperationStates;


return new Specification(
   description: 'Database: PostgreSQL protocol fails controlled error paths',
   test: function () {
      [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($server, false);

      $Database = new Database;
      $Database->Connection->attach($client);
      $Operation = $Database->query('SELECT 1 AS value');
      $Database->advance($Operation);
      fread($server, 8192);
      fclose($server);
      $Database->advance($Operation);

      yield assert(
         assertion: $Operation->state === OperationStates::Failed && $Operation->error === 'PostgreSQL socket closed.',
         description: 'Read-side server close fails the active operation'
      );

      $Database->Connection->disconnect();

      [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($server, false);

      $Database = new Database;
      $Database->Connection->attach($client);
      $Operation = $Database->query('SELECT 1 AS value');
      $Database->advance($Operation);
      fread($server, 8192);
      fwrite($server, 'Z' . pack('N', 3));
      $Database->advance($Operation);

      yield assert(
         assertion: $Operation->state === OperationStates::Failed && $Operation->error === 'PostgreSQL message length is invalid.',
         description: 'Malformed backend message length fails the active operation'
      );

      fclose($server);
      $Database->Connection->disconnect();

      [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($server, false);

      $Database = new Database;
      $Database->Connection->attach($client);
      $Operation = $Database->query('SELECT 1 AS value');
      fclose($server);
      $Database->advance($Operation);

      yield assert(
         assertion: $Operation->state === OperationStates::Failed && str_contains($Operation->error ?? '', 'write'),
         description: 'Write-side server close fails instead of looping forever'
      );

      $Database->Connection->disconnect();
   }
);
