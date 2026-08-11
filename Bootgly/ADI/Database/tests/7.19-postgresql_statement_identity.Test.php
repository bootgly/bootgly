<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Drivers\PostgreSQL;
use Bootgly\ADI\Databases\SQL\Drivers\PostgreSQL\Encoder;


return new Test(
   description: 'Database: PostgreSQL statement identity includes the parameter type signature',
   test: function () {
      [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($server, false);

      // ! max 1 — every operation co-locates on the attached connection, so
      //   the warm statement cache is the one later queries consult.
      $Database = new SQL(['driver' => 'pgsql', 'pool' => ['min' => 0, 'max' => 1]]);
      $Database->Connection->attach($client);
      $Encoder = new Encoder;
      $sql = 'SELECT $1 AS v';

      // ! Warm the statement with an in-range int — the backend confirms int4.
      $Warm = $Database->query($sql, [7]);
      $Database->advance($Warm);
      fread($server, 8192);

      $parseComplete = '1' . pack('N', 4);
      $parameterPayload = pack('n', 1) . pack('N', 23);
      $parameterDescription = 't' . pack('N', strlen($parameterPayload) + 4) . $parameterPayload;
      $bindComplete = '2' . pack('N', 4);
      $commandPayload = "SELECT 1\0";
      $command = 'C' . pack('N', strlen($commandPayload) + 4) . $commandPayload;
      $ready = 'Z' . pack('N', 5) . 'I';
      fwrite($server, "{$parseComplete}{$parameterDescription}{$bindComplete}{$command}{$ready}");
      $Database->advance($Warm);
      $Driver = $Warm->Protocol;

      yield assert(
         assertion: $Warm->error === null
            && $Driver instanceof PostgreSQL
            && ($Driver->statements[$Warm->statement] ?? null) === [23],
         description: 'Warm-up caches the server-confirmed int4 parameter OID'
      );

      $Big = $Database->query($sql, [9000000000]);

      yield assert(
         assertion: $Big->statement !== $Warm->statement,
         description: 'An out-of-int4-range value changes the statement identity'
      );

      $parse = $Encoder->encode(Encoder::PARSE, [
         'statement' => $Big->statement,
         'sql' => $sql,
         'types' => [20],
      ]);

      yield assert(
         assertion: $Big->prepared === false && substr($Big->write, 0, strlen($parse)) === $parse,
         description: 'The int8-typed statement re-Parses declaring OID 20 instead of binding truncated int4 bytes'
      );

      yield assert(
         assertion: ($Driver->statements[$Warm->statement] ?? null) === [23],
         description: 'The int4 statement entry survives the sibling identity in the cache'
      );

      $Again = $Database->query($sql, [8]);
      $bind = $Encoder->encode(Encoder::BIND, [
         'portal' => '',
         'statement' => $Again->statement,
         'parameters' => [8],
         'types' => [23],
      ]);

      yield assert(
         assertion: $Again->statement === $Warm->statement
            && substr($Again->write, 0, strlen($bind)) === $bind,
         description: 'In-range values keep the warm int4 statement and bind directly'
      );

      fclose($server);
      $Database->Connection->disconnect();
   }
);
