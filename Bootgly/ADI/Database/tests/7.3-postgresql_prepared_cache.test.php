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
   description: 'Database: PostgreSQL prepared cache skips Parse on reused SQL',
   test: function () {
      [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($server, false);

      $Database = new Database;
      $Database->Connection->attach($client);
      $sql = 'SELECT $1::int AS value';
      $First = $Database->query($sql, [42]);
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

      $Second = $Database->query($sql, [43]);

      yield assert(
         assertion: $Second->prepared && $Second->write !== '' && $Second->write[0] === 'B',
         description: 'Second operation skips Parse and starts with Bind from statement cache'
      );

      fclose($server);
      $Database->Connection->disconnect();
   }
);
