<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;


return new Test(
   description: 'Database: a PostgreSQL rollback tears the transaction down and discards the statement it overtakes',
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

      $Pool = $Database->Pool;
      $Transaction = $Database->begin();
      $Begin = $Transaction->Operation;
      $Database->advance($Begin);
      fread($server, 8192);
      fwrite($server, $complete('BEGIN'));
      $Database->advance($Begin);

      yield assert(
         assertion: $Begin !== null && $Begin->finished && count($Pool->busy) === 1,
         description: 'The transaction owns one reserved connection after BEGIN'
      );

      // ! The shape Repository::save() hands back: prepared, never advanced.
      $Insert = $Transaction->query("INSERT INTO marker (v) VALUES ('orphan')");

      yield assert(
         assertion: $Insert->write !== '' && $Insert->finished === false,
         description: 'The un-awaited write holds a buffered command that never reached the wire'
      );

      $Rollback = $Transaction->rollback();

      yield assert(
         assertion: $Rollback->Pool !== null && $Rollback->error === null,
         description: 'The teardown reaches the pool instead of being refused'
      );

      yield assert(
         assertion: $Insert->finished && $Insert->write === '',
         description: 'The overtaken statement is discarded, so it can never be flushed later'
      );

      $Database->advance($Rollback);
      $wire = (string) fread($server, 8192);

      yield assert(
         assertion: str_contains($wire, 'ROLLBACK') && str_contains($wire, 'INSERT') === false,
         description: 'Only the rollback reaches the wire'
      );

      fwrite($server, $complete('ROLLBACK'));
      $Database->advance($Rollback);

      yield assert(
         assertion: $Rollback->finished
            && $Rollback->error === null
            && $Pool->busy === []
            && count($Pool->idle) === 1,
         description: 'The reserved connection returns to the pool once the rollback completes'
      );

      // @ The pool is immediately reusable by an ordinary operation.
      $Next = $Database->query('SELECT 1 AS v');

      yield assert(
         assertion: $Next->Connection !== null && $Pool->pending === [],
         description: 'A later query borrows the reclaimed connection instead of parking'
      );

      fclose($server);
      $Database->Connection->disconnect();
   }
);
