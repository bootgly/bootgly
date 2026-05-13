<?php

use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;
use function count;
use function fclose;
use function fread;
use function fwrite;
use function pack;
use function strlen;
use function stream_set_blocking;
use function stream_socket_pair;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Database\Operation\OperationStates;


return new Specification(
   description: 'Database: pool queues pending operations at max capacity',
   test: function () {
      [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($server, false);

      $Database = new SQL([
         'pool' => [
            'min' => 0,
            'max' => 1,
         ],
      ]);
      $Database->Connection->attach($client);

      $First = $Database->query('SELECT 1 AS value');
      $Second = $Database->query('SELECT 2 AS value');

      yield assert(
         assertion: $First->state === OperationStates::Queued && $Second->state === OperationStates::Pending,
         description: 'Pool queues second operation when max capacity is reached'
      );

      yield assert(
         assertion: count($Database->Pool->pending) === 1,
         description: 'Pool tracks one pending operation'
      );

      yield assert(
         assertion: $Second->Connection === null,
         description: 'Pending operation has no stale template connection'
      );

      $Database->advance($First);
      fread($server, 8192);

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
      $rowValueLength = pack('N', 1);
      $rowPayload = "{$rowCount}{$rowValueLength}1";
      $rowLength = pack('N', strlen($rowPayload) + 4);
      $commandPayload = "SELECT 1\0";
      $commandLength = pack('N', strlen($commandPayload) + 4);
      $readyLength = pack('N', 5);
      $backend = "T{$columnLength}{$columnPayload}D{$rowLength}{$rowPayload}C{$commandLength}{$commandPayload}Z{$readyLength}I";

      fwrite($server, $backend);
      $Database->advance($First);

      yield assert(
         assertion: $First->finished && $Second->state === OperationStates::Reading && count($Database->Pool->pending) === 0,
         description: 'Pool promotes pending operation when first operation releases the connection'
      );

      $queryLength = pack('N', strlen('SELECT 2 AS value') + 5);
      $queryExpected = "Q{$queryLength}SELECT 2 AS value\0";

      yield assert(
         assertion: fread($server, 8192) === $queryExpected,
         description: 'Promoted operation writes through the released connection'
      );

      fclose($server);
      $Database->Connection->disconnect();
   }
);
