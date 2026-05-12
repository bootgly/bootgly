<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Database;
use Bootgly\ADI\Database\ConnectionStates;
use Bootgly\ADI\Database\OperationStates;


return new Specification(
   description: 'Database: PostgreSQL ErrorResponse cleans prepared statement cache',
   test: function () {
      [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($server, false);

      $Database = new Database;
      $Database->Connection->attach($client);
      $Operation = $Database->query('SELECT $1::int AS value', [1]);
      $Database->Connection->cache($Operation->statement);
      $Database->advance($Operation);
      fread($server, 8192);

      $errorPayload = "SERROR\0Mcached plan changed\0\0";
      $errorLength = pack('N', strlen($errorPayload) + 4);
      $readyLength = pack('N', 5);
      fwrite($server, "E{$errorLength}{$errorPayload}Z{$readyLength}I");
      $Database->advance($Operation);

      yield assert(
         assertion: $Operation->state === OperationStates::Failed && $Operation->error === 'cached plan changed',
         description: 'ErrorResponse fails the active operation'
      );

      yield assert(
         assertion: isset($Database->Connection->statements[$Operation->statement]) === false,
         description: 'Failed statement is removed from the prepared cache'
      );

      yield assert(
         assertion: $Database->Connection->state === ConnectionStates::Ready
            && count($Database->Pool->idle) === 1
            && $Database->Pool->busy === [],
         description: 'Recoverable PostgreSQL error returns a ready connection to the idle pool'
      );

      fclose($server);
      $Database->Connection->disconnect();
   }
);
