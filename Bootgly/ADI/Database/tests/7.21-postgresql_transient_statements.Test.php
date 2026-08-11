<?php


use ReflectionProperty;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Drivers\PostgreSQL;
use Bootgly\ADI\Databases\SQL\Drivers\PostgreSQL\Encoder;


return new Test(
   description: 'Database: PostgreSQL zero statements budget closes every transient statement',
   test: function () {
      [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($server, false);

      $Database = new SQL(['driver' => 'pgsql', 'statements' => 0, 'pool' => ['min' => 0, 'max' => 1]]);
      $Database->Connection->attach($client);
      $Encoder = new Encoder;

      $First = $Database->query('SELECT $1::int AS a', [1]);
      $Database->advance($First);
      fread($server, 8192);

      $parseComplete = '1' . pack('N', 4);
      $parameterPayload = pack('n', 1) . pack('N', 23);
      $parameterDescription = 't' . pack('N', strlen($parameterPayload) + 4) . $parameterPayload;
      $bindComplete = '2' . pack('N', 4);
      $commandPayload = "SELECT 1\0";
      $command = 'C' . pack('N', strlen($commandPayload) + 4) . $commandPayload;
      $ready = 'Z' . pack('N', 5) . 'I';
      fwrite($server, "{$parseComplete}{$parameterDescription}{$bindComplete}{$command}{$ready}");
      $Database->advance($First);
      $Driver = $First->Protocol;

      yield assert(
         assertion: $First->error === null
            && $Driver instanceof PostgreSQL
            && $Driver->statements === [],
         description: 'A zero budget keeps the statement cache empty after the batch completes'
      );

      $Reflection = new ReflectionProperty(PostgreSQL::class, 'preparing');

      yield assert(
         assertion: $Driver instanceof PostgreSQL && $Reflection->getValue($Driver) === [],
         description: 'The preparing ledger drains instead of leaking one entry per statement'
      );

      $Second = $Database->query('SELECT $1::int AS b', [2]);
      $Database->advance($Second);
      $wire = (string) fread($server, 8192);
      $close = $Encoder->encode(Encoder::CLOSE, [
         'type' => 'S',
         'name' => $First->statement,
      ]);

      yield assert(
         assertion: substr($wire, 0, strlen($close)) === $close
            && substr($wire, strlen($close), 1) === 'P',
         description: 'The next batch closes the transient statement on the wire before its own Parse'
      );

      fclose($server);
      $Database->Connection->disconnect();
   }
);
