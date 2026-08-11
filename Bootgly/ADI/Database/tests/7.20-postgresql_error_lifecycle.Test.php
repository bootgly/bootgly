<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Operation\OperationStates;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Drivers\PostgreSQL;
use Bootgly\ADI\Databases\SQL\Drivers\PostgreSQL\Encoder;


return new Test(
   description: 'Database: PostgreSQL keeps statements across runtime errors and closes evicted ones',
   test: function () {
      [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($server, false);

      // ! max 1 — every operation co-locates on the attached connection.
      $Database = new SQL(['driver' => 'pgsql', 'pool' => ['min' => 0, 'max' => 1]]);
      $Database->Connection->attach($client);
      $Encoder = new Encoder;
      $sql = 'SELECT $1::int AS value';

      // # Warm-up — the backend confirms the statement
      $First = $Database->query($sql, [1]);
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
            && ($Driver->statements[$First->statement] ?? null) === [23],
         description: 'Warm-up caches the server-confirmed statement'
      );

      // # Runtime error (duplicate key) — the server-side statement stays valid
      $Second = $Database->query($sql, [2]);
      $Database->advance($Second);
      fread($server, 8192);

      $errorPayload = "SERROR\0C23505\0Mduplicate key value violates unique constraint \"users_email_key\"\0\0";
      $error = 'E' . pack('N', strlen($errorPayload) + 4) . $errorPayload;
      fwrite($server, "{$error}{$ready}");
      $Database->advance($Second);

      yield assert(
         assertion: $Second->state === OperationStates::Failed
            && $Second->error === 'duplicate key value violates unique constraint "users_email_key"',
         description: 'The duplicate key ErrorResponse fails the operation'
      );

      yield assert(
         assertion: ($Driver->statements[$First->statement] ?? null) === [23],
         description: 'A runtime SQLSTATE keeps the prepared statement cached'
      );

      $Third = $Database->query($sql, [3]);

      yield assert(
         assertion: $Third->prepared === true && $Third->write !== '' && $Third->write[0] === 'B',
         description: 'The next execution binds the surviving statement instead of re-Parsing its name'
      );

      // # Parse failure — no ParseComplete ever arrived: evict stays correct
      $Broken = $Database->query('SELECT $1 FROM missing_table', [1]);
      $Database->advance($Broken);
      fread($server, 8192);

      $missingPayload = "SERROR\0C42P01\0Mrelation \"missing_table\" does not exist\0\0";
      $missing = 'E' . pack('N', strlen($missingPayload) + 4) . $missingPayload;
      fwrite($server, "{$missing}{$ready}");
      $Database->advance($Broken);

      yield assert(
         assertion: $Broken->error === 'relation "missing_table" does not exist'
            && isset($Driver->statements[$Broken->statement]) === false,
         description: 'A Parse failure keeps the statement out of the cache'
      );

      // # Recovery — the queued Close rides ahead of the next batch
      $Recovered = $Database->query($sql, [4]);
      $Database->advance($Recovered);
      $wire = (string) fread($server, 8192);
      $close = $Encoder->encode(Encoder::CLOSE, [
         'type' => 'S',
         'name' => $Broken->statement,
      ]);

      yield assert(
         assertion: substr($wire, 0, strlen($close)) === $close
            && substr($wire, strlen($close), 1) === 'B',
         description: 'The evicted statement is closed on the wire ahead of the next warm batch'
      );

      fclose($server);
      $Database->Connection->disconnect();
   }
);
