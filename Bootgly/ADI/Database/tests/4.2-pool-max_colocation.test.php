<?php

use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;
use function count;
use function fclose;
use function fread;
use function fwrite;
use function pack;
use function str_contains;
use function strlen;
use function stream_set_blocking;
use function stream_socket_pair;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Database\Operation\OperationStates;


return new Specification(
   description: 'Database: pool co-locates operations on one connection at max capacity',
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

      // @ Frame one PostgreSQL simple-query backend response for an int `value` column.
      $backend = static function (string $value): string {
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
         $rowValueLength = pack('N', strlen($value));
         $rowPayload = "{$rowCount}{$rowValueLength}{$value}";
         $rowLength = pack('N', strlen($rowPayload) + 4);

         $commandPayload = "SELECT 1\0";
         $commandLength = pack('N', strlen($commandPayload) + 4);
         $readyLength = pack('N', 5);

         return "T{$columnLength}{$columnPayload}D{$rowLength}{$rowPayload}C{$commandLength}{$commandPayload}Z{$readyLength}I";
      };

      $First = $Database->query('SELECT 1 AS value');
      $Second = $Database->query('SELECT 2 AS value');

      // @ At max capacity the second operation co-locates on the busy
      //   connection (pipelining) instead of being queued pending.
      yield assert(
         assertion: $First->state === OperationStates::Queued && $Second->state === OperationStates::Queued,
         description: 'Pool co-locates the second operation instead of queueing it pending'
      );

      yield assert(
         assertion: count($Database->Pool->pending) === 0,
         description: 'Pool keeps no pending operation when a connection can be shared'
      );

      yield assert(
         assertion: $Second->Connection !== null && $First->Connection === $Second->Connection,
         description: 'Co-located operations share one pooled connection'
      );

      // @ Flush both queries onto the shared connection.
      $Database->advance($First);
      $Database->advance($Second);

      $written = fread($server, 8192);

      yield assert(
         assertion: str_contains($written, 'SELECT 1 AS value') && str_contains($written, 'SELECT 2 AS value'),
         description: 'Both pipelined queries are written through the shared connection'
      );

      // @ Feed both backend responses in pipeline order and drain.
      fwrite($server, $backend('1') . $backend('2'));
      $Database->advance($First);
      $Database->advance($Second);

      yield assert(
         assertion: $First->finished && $Second->finished && count($Database->Pool->pending) === 0,
         description: 'Pool drains both pipelined operations to completion'
      );

      yield assert(
         assertion: ($First->rows[0]['value'] ?? null) === 1 && ($Second->rows[0]['value'] ?? null) === 2,
         description: 'Each pipelined operation resolves its own ordered result'
      );

      fclose($server);
      $Database->Connection->disconnect();
   }
);
