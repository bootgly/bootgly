<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Database;


return new Specification(
   description: 'Database: PostgreSQL advanced text result values are cast from OIDs',
   test: function () {
      [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($server, false);

      $Database = new Database;
      $Database->Connection->attach($client);
      $Operation = $Database->query('SELECT 123.45::numeric AS amount, current_date AS day, now() AS moment, decode(\'48656c6c6f\', \'hex\') AS payload');
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
         . $column('amount', 1700, 0xFFFF)
         . $column('day', 1082, 4)
         . $column('moment', 1114, 8)
         . $column('payload', 17, 0xFFFF);
      $columnLength = pack('N', strlen($columnPayload) + 4);
      $rowPayload = pack('n', 4)
         . pack('N', 6) . '123.45'
         . pack('N', 10) . '2024-01-02'
         . pack('N', 19) . '2024-01-02 03:04:05'
         . pack('N', 12) . '\\x48656c6c6f';
      $rowLength = pack('N', strlen($rowPayload) + 4);
      $commandPayload = "SELECT 1\0";
      $commandLength = pack('N', strlen($commandPayload) + 4);
      $readyLength = pack('N', 5);
      fwrite($server, "T{$columnLength}{$columnPayload}D{$rowLength}{$rowPayload}C{$commandLength}{$commandPayload}Z{$readyLength}I");
      $Database->advance($Operation);
      $Result = $Operation->Result;
      $Row = $Result === null ? [] : ($Result->rows[0] ?? []);

      yield assert(
         assertion: ($Row['amount'] ?? null) === '123.45',
         description: 'PostgreSQL numeric text value preserves precision as string'
      );

      yield assert(
         assertion: ($Row['day'] ?? null) instanceof DateTimeImmutable
            && $Row['day']->format('Y-m-d') === '2024-01-02'
            && ($Row['moment'] ?? null) instanceof DateTimeImmutable
            && $Row['moment']->format('Y-m-d H:i:s') === '2024-01-02 03:04:05',
         description: 'PostgreSQL date and timestamp text values are returned as DateTimeImmutable values'
      );

      yield assert(
         assertion: ($Row['payload'] ?? null) === 'Hello',
         description: 'PostgreSQL bytea hex text value is returned as binary string'
      );

      fclose($server);
      $Database->Connection->disconnect();
   }
);
