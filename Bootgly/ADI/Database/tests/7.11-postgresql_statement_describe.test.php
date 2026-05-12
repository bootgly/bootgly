<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Database;
use Bootgly\ADI\Database\Connection\Protocols\PostgreSQL\Encoder;


return new Specification(
   description: 'Database: PostgreSQL describes prepared statements before Bind',
   test: function () {
      [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($server, false);

      $Database = new Database;
      $Database->Connection->attach($client);
      $sql = 'SELECT $1::int AS value';
      $Operation = $Database->query($sql, [42]);
      $Encoder = new Encoder;
      $parse = $Encoder->encode(Encoder::PARSE, [
         'statement' => $Operation->statement,
         'sql' => $sql,
         'types' => [23],
      ]);
      $describe = $Encoder->encode(Encoder::DESCRIBE, [
         'type' => 'S',
         'name' => $Operation->statement,
      ]);

      yield assert(
         assertion: substr($Operation->write, strlen($parse), strlen($describe)) === $describe,
         description: 'Extended Query batch describes the statement immediately after Parse'
      );

      $Database->advance($Operation);
      fread($server, 8192);

      $parseLength = pack('N', 4);
      $parseComplete = "1{$parseLength}";
      $parameterPayload = pack('n', 1) . pack('N', 23);
      $parameterLength = pack('N', strlen($parameterPayload) + 4);
      $columnCount = pack('n', 1);
      $columnTable = pack('N', 0);
      $columnAttribute = pack('n', 0);
      $columnType = pack('N', 23);
      $columnSize = pack('n', 4);
      $columnModifier = pack('N', 0xFFFFFFFF);
      $columnFormat = pack('n', 0);
      $columnPayload = "{$columnCount}value\0{$columnTable}{$columnAttribute}{$columnType}{$columnSize}{$columnModifier}{$columnFormat}";
      $columnLength = pack('N', strlen($columnPayload) + 4);
      $bindLength = pack('N', 4);
      $bindComplete = "2{$bindLength}";
      $rowPayload = pack('n', 1) . pack('N', 2) . '42';
      $rowLength = pack('N', strlen($rowPayload) + 4);
      $commandPayload = "SELECT 1\0";
      $commandLength = pack('N', strlen($commandPayload) + 4);
      $readyLength = pack('N', 5);
      fwrite($server, "{$parseComplete}t{$parameterLength}{$parameterPayload}T{$columnLength}{$columnPayload}{$bindComplete}D{$rowLength}{$rowPayload}C{$commandLength}{$commandPayload}Z{$readyLength}I");
      $Database->advance($Operation);
      $Result = $Operation->Result;

      yield assert(
         assertion: $Operation->parameterTypes === [23]
            && ($Database->Connection->statements[$Operation->statement] ?? null) === [23]
            && $Result !== null
            && $Result->rows === [['value' => 42]],
         description: 'ParameterDescription and statement RowDescription are applied before BindComplete'
      );

      $Again = $Database->query($sql, [43]);
      $bind = $Encoder->encode(Encoder::BIND, [
         'portal' => '',
         'statement' => $Again->statement,
         'parameters' => [43],
         'types' => [23],
      ]);

      yield assert(
         assertion: substr($Again->write, 0, strlen($bind)) === $bind,
         description: 'Cached statement uses server-confirmed parameter OIDs for binary Bind formats'
      );

      fclose($server);
      $Database->Connection->disconnect();
   }
);
