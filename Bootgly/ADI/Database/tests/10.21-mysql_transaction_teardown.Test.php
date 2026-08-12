<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;


return new Test(
   description: 'Database: a MySQL rollback tears the transaction down and discards the statement it overtakes',
   test: function () {
      $connect = function (): array {
         [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         stream_set_blocking($client, false);
         stream_set_blocking($server, false);

         $Database = new SQL([
            'driver' => 'mysql',
            'secure' => ['mode' => 'disable'],
            'timeout' => 30.0,
            'pool' => [
               'min' => 0,
               'max' => 1,
            ],
         ]);
         $Database->Connection->attach($client);

         return [$Database, $server];
      };
      $ok = "\x00\x01\x00" . pack('v', 0) . pack('v', 0);
      $frame = fn (string $payload, int $sequence): string =>
         substr(pack('V', strlen($payload)), 0, 3) . chr($sequence) . $payload;

      // # An un-awaited write must not survive the rollback that overtakes it
      [$Database, $server] = $connect();
      $Pool = $Database->Pool;

      $Transaction = $Database->begin();
      $Begin = $Transaction->Operation;
      $Database->advance($Begin);
      fread($server, 8192);
      fwrite($server, $frame($ok, 1));
      $Database->advance($Begin);

      yield assert(
         assertion: $Begin !== null && $Begin->finished && count($Pool->busy) === 1,
         description: 'The transaction owns one reserved connection after BEGIN'
      );

      // ! Exactly what Repository::save() hands back: prepared, never advanced.
      $Insert = $Transaction->query("INSERT INTO marker (v) VALUES ('orphan')");

      yield assert(
         assertion: $Insert->write !== '' && $Insert->finished === false,
         description: 'The un-awaited write holds a buffered command that never reached the wire'
      );

      // @ $work threw — the teardown runs with that statement still outstanding.
      $Rollback = $Transaction->rollback();

      yield assert(
         assertion: $Rollback->Pool !== null && $Rollback->error === null,
         description: 'The teardown reaches the pool instead of being refused'
      );

      yield assert(
         assertion: $Insert->finished && $Insert->write === '',
         description: 'The overtaken statement is discarded, so it can never be flushed later'
      );

      yield assert(
         assertion: $Insert->Connection === null && $Insert->Protocol === null,
         description: 'The discarded statement is detached, so awaiting it later cannot release a connection somebody else owns'
      );

      $Database->advance($Rollback);
      $wire = (string) fread($server, 8192);

      yield assert(
         assertion: str_contains($wire, 'ROLLBACK') && str_contains($wire, 'INSERT') === false,
         description: 'Only the rollback reaches the wire'
      );

      fwrite($server, $frame($ok, 1));
      $Database->advance($Rollback);

      yield assert(
         assertion: $Rollback->finished
            && $Pool->busy === []
            && count($Pool->idle) === 1,
         description: 'The reserved connection returns to the pool once the rollback completes'
      );

      fclose($server);
      $Database->Connection->disconnect();

      // # commit() still refuses, and the rollback behind it still tears down
      [$Database, $server] = $connect();
      $Pool = $Database->Pool;

      $Transaction = $Database->begin();
      $Begin = $Transaction->Operation;
      $Database->advance($Begin);
      fread($server, 8192);
      fwrite($server, $frame($ok, 1));
      $Database->advance($Begin);

      $Pending = $Transaction->query("INSERT INTO marker (v) VALUES ('pending')");
      $Commit = $Transaction->commit();

      yield assert(
         assertion: $Commit->error === 'SQL transaction operation is still active.'
            && $Commit->Pool === null,
         description: 'A commit with a statement outstanding still fails closed'
      );

      $Rollback = $Transaction->rollback();
      $Database->advance($Rollback);
      fread($server, 8192);
      fwrite($server, $frame($ok, 1));
      $Database->advance($Rollback);

      yield assert(
         assertion: $Pending->finished
            && $Rollback->finished
            && $Pool->busy === []
            && count($Pool->idle) === 1,
         description: 'The rollback after a refused commit still reclaims the reservation'
      );

      fclose($server);
      $Database->Connection->disconnect();
   }
);
