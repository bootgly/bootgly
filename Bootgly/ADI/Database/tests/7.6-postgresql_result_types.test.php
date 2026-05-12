<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Databases\SQL;


return new Specification(
   description: 'Database: PostgreSQL text result values are cast from column OIDs',
   test: function () {
      [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($server, false);

      $Database = new SQL;
      $Database->Connection->attach($client);
      $Operation = $Database->query('SELECT 42 AS integer, true AS flag, 1.5::float8 AS ratio, text \'hello\' AS label');
      $Database->advance($Operation);
      fread($server, 8192);

      $column = static function (string $name, int $type, int $size): string {
         return "{$name}\0"
            . pack('N', 0)
            . pack('n', 0)
            . pack('N', $type)
            . pack('n', $size)
            . pack('N', 0xFFFFFFFF)
            . pack('n', 0);
      };
      $columnPayload = pack('n', 4)
         . $column('integer', 23, 4)
         . $column('flag', 16, 1)
         . $column('ratio', 701, 8)
         . $column('label', 25, 0xFFFF);
      $columnLength = pack('N', strlen($columnPayload) + 4);
      $rowPayload = pack('n', 4)
         . pack('N', 2) . '42'
         . pack('N', 1) . 't'
         . pack('N', 3) . '1.5'
         . pack('N', 5) . 'hello';
      $rowLength = pack('N', strlen($rowPayload) + 4);
      $commandPayload = "SELECT 1\0";
      $commandLength = pack('N', strlen($commandPayload) + 4);
      $readyLength = pack('N', 5);
      fwrite($server, "T{$columnLength}{$columnPayload}D{$rowLength}{$rowPayload}C{$commandLength}{$commandPayload}Z{$readyLength}I");
      $Database->advance($Operation);
      $Result = $Operation->Result;

      yield assert(
         assertion: $Result !== null && $Result->rows === [[
            'integer' => 42,
            'flag' => true,
            'ratio' => 1.5,
            'label' => 'hello',
         ]],
         description: 'PostgreSQL int, bool and float text values are returned as typed PHP values'
      );

      fclose($server);
      $Database->Connection->disconnect();
   }
);
