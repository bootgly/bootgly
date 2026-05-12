<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Drivers\PostgreSQL;


return new Specification(
   description: 'Database: PostgreSQL prepared cache evicts old statements with Close',
   test: function () {
      [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($server, false);

      $Database = new SQL([
         'statements' => 1,
      ]);
      $Database->Connection->attach($client);
      $First = $Database->query('SELECT $1::int AS first', [1]);
      $Database->advance($First);
      fread($server, 8192);

      $parseLength = pack('N', 4);
      $parseComplete = "1{$parseLength}";
      $bindLength = pack('N', 4);
      $bindComplete = "2{$bindLength}";
      $commandPayload = "SELECT 0\0";
      $commandLength = pack('N', strlen($commandPayload) + 4);
      $readyLength = pack('N', 5);
      $backend = "{$parseComplete}{$bindComplete}C{$commandLength}{$commandPayload}Z{$readyLength}I";
      fwrite($server, $backend);
      $Database->advance($First);
      $Driver = $First->Protocol;

      $Second = $Database->query('SELECT $1::int AS second', [2]);

      yield assert(
         assertion: $Second->write !== '' && $Second->write[0] === 'C',
         description: 'Second distinct SQL starts by closing the evicted prepared statement'
      );

      yield assert(
         assertion: $Driver instanceof PostgreSQL && isset($Driver->statements[$First->statement]) === false,
         description: 'Evicted statement is removed from the local prepared cache'
      );

      fclose($server);
      $Database->Connection->disconnect();
   }
);
