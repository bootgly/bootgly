<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;


return new Test(
   description: 'SQL Transaction: begin() called while the opening BEGIN is in flight names the cause',
   test: function () {
      [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($server, false);

      $complete = static function (string $command): string {
         $command = "{$command}\0";

         return 'C' . pack('N', strlen($command) + 4) . $command . 'Z' . pack('N', 5) . 'I';
      };
      $named = 'SQL transaction BEGIN is still in flight: await it before the next operation.';
      $generic = 'SQL transaction operation is still active.';

      $Database = new SQL([
         'timeout' => 30.0,
         'pool' => ['min' => 0, 'max' => 1],
      ]);
      $Database->Connection->attach($client);

      // # begin() while the constructor's BEGIN is in flight
      $Transaction = $Database->begin();
      $Begin = $Transaction->Operation;
      $First = $Transaction->begin();
      $Second = $Transaction->begin();

      yield assert(
         assertion: $First->error === $named && $First->Pool === null
            && $Second->error === $named
            && $Transaction->Operation === $Begin && $Transaction->depth === 1,
         description: 'The refusal names the BEGIN nobody awaited yet, every time, and changes nothing, found: '
            . json_encode([$First->error, $Second->error, $Transaction->depth])
      );

      // # Scope is begin() only: every other call refused for the same
      //   reason keeps the text it has always had.
      $Query = $Transaction->query('SELECT 1');
      $Commit = $Transaction->commit();
      $Save = $Transaction->save();

      yield assert(
         assertion: $Query->error === $generic && $Commit->error === $generic && $Save->error === $generic,
         description: 'query(), commit() and save() during the BEGIN keep the generic text, found: '
            . json_encode([$Query->error, $Commit->error, $Save->error])
      );

      $Database->advance($Begin);
      fread($server, 8192);
      fwrite($server, $complete('BEGIN'));
      $Database->advance($Begin);

      // # Control 1 — begin() while a statement is in flight
      $Statement = $Transaction->query('SELECT 2');
      $During = $Transaction->begin();

      yield assert(
         assertion: $Begin !== null && $Begin->finished
            && $During->error === $generic && $Transaction->depth === 1,
         description: 'Control: begin() during a statement keeps the generic text, found: ' . json_encode($During->error)
      );

      $Database->advance($Statement);
      fread($server, 8192);
      fwrite($server, $complete('SELECT 0'));
      $Database->advance($Statement);

      // # Control 2 — begin() while a SAVEPOINT is in flight
      $Savepoint = $Transaction->begin();
      $Nested = $Transaction->begin();

      yield assert(
         assertion: $Statement->finished
            && $Savepoint->error === null && $Savepoint->finished === false
            && $Nested->error === $generic && $Transaction->depth === 2,
         description: 'Control: begin() during a SAVEPOINT keeps the generic text, found: ' . json_encode($Nested->error)
      );

      $Database->advance($Savepoint);
      fread($server, 8192);
      fwrite($server, $complete('SAVEPOINT'));
      $Database->advance($Savepoint);

      // # A transaction reopened on the same object: its new BEGIN is named too
      $Release = $Transaction->commit();
      $Database->advance($Release);
      fread($server, 8192);
      fwrite($server, $complete('RELEASE'));
      $Database->advance($Release);

      $Commit = $Transaction->commit();
      $Database->advance($Commit);
      fread($server, 8192);
      fwrite($server, $complete('COMMIT'));
      $Database->advance($Commit);

      $Reopened = $Transaction->begin();
      $Early = $Transaction->begin();

      yield assert(
         assertion: $Commit->finished && $Commit->error === null
            && $Reopened->error === null && $Reopened->finished === false
            && $Early->error === $named,
         description: 'begin() during the BEGIN of a reopened transaction is named as well, found: ' . json_encode($Early->error)
      );

      fclose($server);
      $Database->Connection->disconnect();
   }
);
