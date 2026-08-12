<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;


return new Test(
   description: 'Database: the SQL transaction serial guard survives being refused once',
   test: function () {
      [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($server, false);

      $complete = static function (string $command): string {
         $command = "{$command}\0";
         $commandLength = pack('N', strlen($command) + 4);
         $readyLength = pack('N', 5);

         return "C{$commandLength}{$command}Z{$readyLength}I";
      };

      $Database = new SQL([
         'timeout' => 30.0,
         'pool' => [
            'min' => 0,
            'max' => 1,
         ],
      ]);
      $Database->Connection->attach($client);

      $Transaction = $Database->begin();
      $Begin = $Transaction->Operation;
      $Database->advance($Begin);
      fread($server, 8192);
      fwrite($server, $complete('BEGIN'));
      $Database->advance($Begin);

      yield assert(
         assertion: $Begin !== null && $Begin->finished,
         description: 'The transaction is open'
      );

      // ! One statement in flight — never advanced, so it never completes.
      $InFlight = $Transaction->query('SELECT 1 AS a');

      $First = $Transaction->query('SELECT 2 AS b');

      yield assert(
         assertion: $First->error === 'SQL transaction operation is still active.'
            && $First->Pool === null,
         description: 'A second statement is refused while the first is still active'
      );

      // @ The refusal must be durable: the serial surface is still occupied by
      //   the same in-flight statement, not by the operation that was refused.
      $Second = $Transaction->query('SELECT 3 AS c');

      yield assert(
         assertion: $Second->error === 'SQL transaction operation is still active.'
            && $Second->Pool === null,
         description: 'A third statement is refused too — one refusal does not open the gate'
      );

      // @ The refusal must not have consumed the pool either: the in-flight
      //   statement still owns the reserved connection, and nothing was queued
      //   behind it on the wire.
      $wire = (string) fread($server, 8192);

      yield assert(
         assertion: $wire === '' && count($Database->Pool->busy) === 1 && $Database->Pool->pending === [],
         description: 'Neither refusal reaches the wire or the pool'
      );

      fclose($server);
      $Database->Connection->disconnect();
   }
);
