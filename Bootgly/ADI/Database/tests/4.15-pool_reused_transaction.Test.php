<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Operation\OperationStates;
use Bootgly\ADI\Database\Pool;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Operation;


return new Test(
   description: 'Pool: a reused transaction acquires a fresh exclusive claim instead of stealing a busy connection',
   test: function () {
      // ! A complete PostgreSQL simple-query answer: CommandComplete followed
      //   by ReadyForQuery.
      $Complete = static function (string $command): string {
         $command = "{$command}\0";

         return 'C' . pack('N', strlen($command) + 4) . $command
            . 'Z' . pack('N', 5) . 'I';
      };
      /**
       * Open one PostgreSQL pool over a socketpair, with the backend peer.
       *
       * @return array{SQL, resource}
       */
      $Open = static function (): array {
         [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         stream_set_blocking($client, false);
         stream_set_blocking($server, false);

         $Database = new SQL([
            'timeout' => 30.0,
            'pool' => ['min' => 0, 'max' => 1],
         ]);
         $Database->Connection->attach($client);

         return [$Database, $server];
      };
      $Reserved = static fn (Pool $Pool): int => count(
         (new ReflectionProperty(Pool::class, 'locked'))->getValue($Pool)
      );

      // # Reusing one Transaction object after COMMIT
      //   The completed teardown carried the old connection, but no transaction
      //   owns that session any more. Recovering that stale pin lets a new BEGIN
      //   reserve a connection that an unrelated reader is already using.
      [$Database, $server] = $Open();
      $Pool = $Database->Pool;

      $Transaction = $Database->begin();
      $Opened = $Transaction->Operation;

      $Database->advance($Opened);
      fread($server, 8192);
      fwrite($server, $Complete('BEGIN'));
      $Database->advance($Opened);

      $Committed = $Transaction->commit();
      $Database->advance($Committed);
      fread($server, 8192);
      fwrite($server, $Complete('COMMIT'));
      $Database->advance($Committed);

      $Reader = $Database->query('SELECT 1 AS value');
      $Database->advance($Reader);
      fread($server, 8192);

      $Reopened = $Transaction->begin();

      yield assert(
         assertion: $Reopened->state === OperationStates::Pending
            && $Reopened->Connection === null
            && $Reopened->Protocol === null
            && $Pool->pending === [$Reopened]
            && $Reserved($Pool) === 0
            && count($Pool->busy) === 1
            && $Reader->finished === false,
         description: 'A reused BEGIN waits unpinned while an unrelated reader owns the only connection'
      );

      // @ Completing the reader returns capacity. promote() must assign the
      //   pending BEGIN, reserve that connection and put BEGIN on the wire.
      fwrite($server, $Complete('SELECT 1'));
      $Database->advance($Reader);

      $wire = fread($server, 8192);
      $promoted = $Reopened->state === OperationStates::Reading
         && $Reopened->Connection !== null
         && $Reopened->Connection === $Reader->Connection
         && $Pool->pending === []
         && $Reserved($Pool) === 1
         && count($Pool->busy) === 1
         && str_contains($wire, "BEGIN\0");

      yield assert(
         assertion: $promoted,
         description: 'Reader completion promotes and reserves the waiting BEGIN'
      );

      fwrite($server, $Complete('BEGIN'));
      $Database->advance($Reopened);

      // # An active transaction is deliberately pinned
      //   Once BEGIN succeeds, ordinary statements are part of that server
      //   transaction and must keep using its reserved connection.
      $Inside = $Transaction->query('SELECT 2 AS inside');

      yield assert(
         assertion: $Inside->state === OperationStates::Queued
            && $Inside->Connection === $Reopened->Connection
            && $Inside->Protocol === $Reopened->Protocol
            && $Pool->pending === []
            && $Reserved($Pool) === 1,
         description: 'Statements in an active transaction remain pinned to its reserved connection'
      );

      $Database->advance($Inside);
      fread($server, 8192);
      fwrite($server, $Complete('SELECT 1'));
      $Database->advance($Inside);

      $Rolled = $Transaction->rollback();
      $Database->advance($Rolled);
      fread($server, 8192);
      fwrite($server, $Complete('ROLLBACK'));
      $Database->advance($Rolled);

      fclose($server);
      $Database->Connection->disconnect();

      // # The transaction itself forgets a completed lifetime's pin
      //   Pool normalization protects a busy pin, but an idle historical
      //   connection is not busy. If its socket dies before reuse, recovering
      //   that stale pin takes acquire()'s permanent pinned-loss branch instead
      //   of the ordinary unpinned reconnect path.
      [$Database, $server] = $Open();
      $Pool = $Database->Pool;

      $Transaction = $Database->begin();
      $Opened = $Transaction->Operation;
      $Database->advance($Opened);
      fread($server, 8192);
      fwrite($server, $Complete('BEGIN'));
      $Database->advance($Opened);

      $Committed = $Transaction->commit();
      $Database->advance($Committed);
      fread($server, 8192);
      fwrite($server, $Complete('COMMIT'));
      $Database->advance($Committed);

      $Historical = $Committed->Connection;

      if (is_resource($Historical?->socket)) {
         fclose($Historical->socket);
      }

      $Reopened = $Transaction->begin();

      yield assert(
         assertion: $Historical !== null
            && $Reopened->finished === false
            && $Reopened->error === null
            && $Reopened->state === OperationStates::Queued
            && $Reopened->Connection === $Historical
            && $Reserved($Pool) === 1,
         description: 'A reused transaction does not treat its completed lifetime connection as a dead pin'
      );

      fclose($server);
      $Database->Connection->disconnect();

      // # Defensive pool boundary
      //   A caller can construct an exclusive operation with a stale pin
      //   without going through Transaction. assign() must normalize that pin
      //   whenever the named connection is already busy, then follow the same
      //   capacity and promotion path as any other exclusive request.
      [$Database, $server] = $Open();
      $Pool = $Database->Pool;

      $Reader = $Database->query('SELECT 3 AS value');
      $Database->advance($Reader);
      fread($server, 8192);

      $Exclusive = new Operation($Reader->Connection, 'BEGIN', [], 30.0);
      $Exclusive->lock = true;
      $Pool->assign($Exclusive);

      yield assert(
         assertion: $Exclusive->state === OperationStates::Pending
            && $Exclusive->Connection === null
            && $Exclusive->Protocol === null
            && $Pool->pending === [$Exclusive]
            && $Reserved($Pool) === 0
            && count($Pool->busy) === 1,
         description: 'The pool normalizes a stale busy pin before assigning an exclusive operation'
      );

      fwrite($server, $Complete('SELECT 1'));
      $Database->advance($Reader);

      $wire = fread($server, 8192);
      $promoted = $Exclusive->state === OperationStates::Reading
         && $Exclusive->Connection === $Reader->Connection
         && $Exclusive->Protocol !== null
         && $Pool->pending === []
         && $Reserved($Pool) === 1
         && count($Pool->busy) === 1
         && str_contains($wire, "BEGIN\0");

      yield assert(
         assertion: $promoted,
         description: 'The normalized exclusive operation is promoted only after the busy owner finishes'
      );

      fwrite($server, $Complete('BEGIN'));
      $Database->advance($Exclusive);

      fclose($server);
      $Database->Connection->disconnect();

      // # Reassigning the same exclusive operation is idempotent
      //   Its own connection is necessarily busy and locked after the first
      //   assign. Treating that as a stale foreign pin strands the original
      //   reservation and parks an operation that can never be promoted.
      [$Database, $server] = $Open();
      $Pool = $Database->Pool;

      $Begin = new Operation(null, 'BEGIN', [], 30.0);
      $Begin->lock = true;
      $Pool->assign($Begin);

      $Connection = $Begin->Connection;
      $Protocol = $Begin->Protocol;
      $write = $Begin->write;

      $Pool->assign($Begin);

      yield assert(
         assertion: $Begin->state === OperationStates::Queued
            && $Begin->Connection === $Connection
            && $Begin->Protocol === $Protocol
            && $Begin->write === $write
            && $Pool->pending === []
            && $Reserved($Pool) === 1
            && count($Pool->busy) === 1,
         description: 'Assigning the same live BEGIN twice preserves its one exclusive claim'
      );

      fclose($server);
      $Database->Connection->disconnect();
   }
);
