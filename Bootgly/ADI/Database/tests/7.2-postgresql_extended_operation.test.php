<?php

use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;
use function fclose;
use function fread;
use function fwrite;
use function pack;
use function strlen;
use function stream_set_blocking;
use function stream_socket_pair;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Database;


return new Specification(
   description: 'Database: PostgreSQL operation with parameters uses Extended Query Protocol',
   test: function () {
      [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($server, false);

      $Database = new Database;
      $Database->Pool->attach($Database->Connection->attach($client));
      $sql = 'SELECT $1::int AS value';
      $Operation = $Database->query($sql, [42]);
      $expected = $Operation->write;

      yield assert(
         assertion: $expected !== '' && $expected[0] === 'P',
         description: 'Parameterized operation starts with Parse message'
      );

      $Database->advance($Operation);

      yield assert(
         assertion: fread($server, 8192) === $expected,
         description: 'Operation writes Extended Query batch to stream'
      );

      $parseLength = pack('N', 4);
      $parseComplete = "1{$parseLength}";
      $bindLength = pack('N', 4);
      $bindComplete = "2{$bindLength}";
      $columnCount = pack('n', 1);
      $columnTable = pack('N', 0);
      $columnAttribute = pack('n', 0);
      $columnType = pack('N', 23);
      $columnSize = pack('n', 4);
      $columnModifier = pack('N', 0xFFFFFFFF);
      $columnFormat = pack('n', 0);
      $columnPayload = "{$columnCount}value\0{$columnTable}{$columnAttribute}{$columnType}{$columnSize}{$columnModifier}{$columnFormat}";
      $columnLength = pack('N', strlen($columnPayload) + 4);
      $rowCount = pack('n', 1);
      $rowValueLength = pack('N', 2);
      $rowPayload = "{$rowCount}{$rowValueLength}42";
      $rowLength = pack('N', strlen($rowPayload) + 4);
      $commandPayload = "SELECT 1\0";
      $commandLength = pack('N', strlen($commandPayload) + 4);
      $readyLength = pack('N', 5);
      $backend = "{$parseComplete}{$bindComplete}T{$columnLength}{$columnPayload}D{$rowLength}{$rowPayload}C{$commandLength}{$commandPayload}Z{$readyLength}I";

      fwrite($server, $backend);
      $Database->advance($Operation);
      $Result = $Operation->Result;

      yield assert(
         assertion: $Result !== null && $Result->rows === [['value' => 42]],
         description: 'Extended operation resolves typed decoded result row'
      );

      yield assert(
         assertion: $Operation->prepared && ($Database->Connection->statements[$Operation->statement] ?? false),
         description: 'ParseComplete stores prepared statement in connection cache'
      );

      fclose($server);
      $Database->Connection->disconnect();
   }
);
