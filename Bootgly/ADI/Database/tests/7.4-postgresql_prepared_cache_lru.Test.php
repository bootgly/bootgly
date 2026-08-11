<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Drivers\PostgreSQL;
use Bootgly\ADI\Databases\SQL\Drivers\PostgreSQL\Encoder;


return new Test(
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
         assertion: $Second->write !== '' && $Second->write[0] === 'P',
         description: 'Second distinct SQL composes its own batch — the paired Close rides the driver buffer'
      );

      yield assert(
         assertion: $Driver instanceof PostgreSQL && isset($Driver->statements[$First->statement]) === false,
         description: 'Evicted statement is removed from the local prepared cache'
      );

      $Database->advance($Second);
      $wire = (string) fread($server, 8192);
      $Encoder = new Encoder;
      $close = $Encoder->encode(Encoder::CLOSE, [
         'type' => 'S',
         'name' => $First->statement,
      ]);

      yield assert(
         assertion: substr($wire, 0, strlen($close)) === $close
            && substr($wire, strlen($close), 1) === 'P',
         description: 'The evicted statement is closed on the wire ahead of the next batch'
      );

      fclose($server);
      $Database->Connection->disconnect();
   }
);
