<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Database\Operation\OperationStates;


return new Specification(
   description: 'Database: PostgreSQL pipeline releases completed sibling operations',
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
      $Second = $Database->query('SELECT broken');
      $Database->advance($Second);
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
      $rowPayload = pack('n', 1) . pack('N', 1) . '1';
      $rowLength = pack('N', strlen($rowPayload) + 4);
      $commandPayload = "SELECT 1\0";
      $commandLength = pack('N', strlen($commandPayload) + 4);
      $readyLength = pack('N', 5);
      $success = "T{$columnLength}{$columnPayload}D{$rowLength}{$rowPayload}C{$commandLength}{$commandPayload}Z{$readyLength}I";
      $errorPayload = "SERROR\0Mpipeline failed\0\0";
      $errorLength = pack('N', strlen($errorPayload) + 4);
      $failure = "E{$errorLength}{$errorPayload}Z{$readyLength}I";
      fwrite($server, "{$success}{$failure}");
      $Database->advance($First);

      yield assert(
         assertion: $First->state === OperationStates::Finished
            && $Second->state === OperationStates::Failed
            && $Second->error === 'pipeline failed',
         description: 'Pipeline read resolves success and failed sibling operations'
      );

      yield assert(
         assertion: $Database->Pool->created === 1
            && count($Database->Pool->idle) === 1
            && $Database->Pool->busy === [],
         description: 'Failed pipelined sibling is released without dropping a ready connection'
      );

      fclose($server);
   }
);
