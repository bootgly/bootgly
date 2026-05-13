<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Database\Operation\OperationStates;


return new Specification(
   description: 'Database: PostgreSQL pipelines multiple operations on one ready connection',
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
      $Database->advance($First);
      fread($server, 8192);
      $Second = $Database->query('SELECT 2 AS value');

      yield assert(
         assertion: $Second->Connection === $First->Connection
            && $Second->state === OperationStates::Queued
            && $Database->Pool->pending === [],
         description: 'Pool assigns a second operation to the busy ready connection instead of pending it'
      );

      $Database->advance($Second);
      fread($server, 8192);

      yield assert(
         assertion: $First->state === OperationStates::Reading && $Second->state === OperationStates::Reading,
         description: 'Both pipelined operations wait for ordered backend responses'
      );

      $result = static function (string $value): string {
         $columnCount = pack('n', 1);
         $columnTable = pack('N', 0);
         $columnAttribute = pack('n', 0);
         $columnType = pack('N', 23);
         $columnSize = pack('n', 4);
         $columnModifier = pack('N', 0xFFFFFFFF);
         $columnFormat = pack('n', 0);
         $columnPayload = "{$columnCount}value\0{$columnTable}{$columnAttribute}{$columnType}{$columnSize}{$columnModifier}{$columnFormat}";
         $columnLength = pack('N', strlen($columnPayload) + 4);
         $rowPayload = pack('n', 1) . pack('N', strlen($value)) . $value;
         $rowLength = pack('N', strlen($rowPayload) + 4);
         $commandPayload = "SELECT 1\0";
         $commandLength = pack('N', strlen($commandPayload) + 4);
         $readyLength = pack('N', 5);

         return "T{$columnLength}{$columnPayload}D{$rowLength}{$rowPayload}C{$commandLength}{$commandPayload}Z{$readyLength}I";
      };

      fwrite($server, $result('1') . $result('2'));
      $Database->advance($First);

      yield assert(
         assertion: $First->Result !== null
            && $Second->Result !== null
            && $First->Result->rows === [['value' => 1]]
            && $Second->Result->rows === [['value' => 2]],
         description: 'One backend read resolves pipelined operations in order'
      );

      yield assert(
         assertion: $Database->Pool->busy === [] && count($Database->Pool->idle) === 1,
         description: 'Connection returns to idle only after the pipeline drains'
      );

      fclose($server);
      $Database->Connection->disconnect();
   }
);
