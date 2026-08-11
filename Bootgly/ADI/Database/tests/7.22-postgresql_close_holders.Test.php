<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Drivers\PostgreSQL\Encoder;


return new Test(
   description: 'Database: PostgreSQL queued Closes wait for the batches still binding the statement',
   test: function () {
      [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($server, false);

      // ! statements => 0 evicts at every ReadyForQuery, so a queued Close and
      //   an in-flight sibling of the same statement overlap by design.
      $Database = new SQL(['driver' => 'pgsql', 'statements' => 0, 'pool' => ['min' => 0, 'max' => 1]]);
      $Database->Connection->attach($client);
      $Encoder = new Encoder;
      $sql = 'SELECT $1::int AS a';

      // # The cold batch flushes and stays in flight
      $First = $Database->query($sql, [1]);
      $Database->advance($First);
      fread($server, 8192);

      // # A sibling composes a warm Bind of the same statement mid-flight
      $Second = $Database->query($sql, [2]);

      yield assert(
         assertion: $Second->statement === $First->statement
            && $Second->prepared === true
            && $Second->write !== ''
            && $Second->write[0] === 'B',
         description: 'A sibling composed while the Parse is in flight binds the statement without Parsing it'
      );

      $parseComplete = '1' . pack('N', 4);
      $parameterPayload = pack('n', 1) . pack('N', 23);
      $parameterDescription = 't' . pack('N', strlen($parameterPayload) + 4) . $parameterPayload;
      $bindComplete = '2' . pack('N', 4);
      $commandPayload = "SELECT 1\0";
      $command = 'C' . pack('N', strlen($commandPayload) + 4) . $commandPayload;
      $ready = 'Z' . pack('N', 5) . 'I';
      fwrite($server, "{$parseComplete}{$parameterDescription}{$bindComplete}{$command}{$ready}");
      $Database->advance($First);

      $close = $Encoder->encode(Encoder::CLOSE, [
         'type' => 'S',
         'name' => $First->statement,
      ]);

      // # An unrelated batch flushes first — it must not close the held name
      $Other = $Database->query('SELECT $1::int AS c', [3]);
      $Database->advance($Other);
      $wire = (string) fread($server, 8192);

      yield assert(
         assertion: $First->error === null
            && $wire !== ''
            && $wire[0] === 'P'
            && str_contains($wire, $close) === false,
         description: 'An unrelated batch never carries a Close for a statement another composed batch still binds'
      );

      // # The held batch reaches the socket with its statement still alive
      $Database->advance($Second);
      $wire = (string) fread($server, 8192);

      yield assert(
         assertion: $wire !== '' && $wire[0] === 'B' && str_contains($wire, $close) === false,
         description: 'The warm sibling binds a statement the backend still holds'
      );

      // # Nothing holds the name anymore — the queued Close ships
      $Third = $Database->query('SELECT $1::int AS d', [4]);
      $Database->advance($Third);
      $wire = (string) fread($server, 8192);

      yield assert(
         assertion: substr($wire, 0, strlen($close)) === $close,
         description: 'Once every holder has flushed, the queued Close leads the next batch'
      );

      fclose($server);
      $Database->Connection->disconnect();
   }
);
