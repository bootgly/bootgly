<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Transaction;


return new Test(
   description: 'Database: a named savepoint teardown truncates the stack the way the server does',
   test: function () {
      $complete = static function (string $command): string {
         $command = "{$command}\0";

         return 'C' . pack('N', strlen($command) + 4) . $command . 'Z' . pack('N', 5) . 'I';
      };

      /**
       * Opens a transaction on a socketpair and drives it to a live BEGIN.
       *
       * @return array{SQL, Transaction, resource}
       */
      $begin = static function () use ($complete): array {
         [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         stream_set_blocking($client, false);
         stream_set_blocking($server, false);

         $Database = new SQL(['pool' => ['min' => 0, 'max' => 1]]);
         $Database->Connection->attach($client);

         $Transaction = $Database->begin();
         $Database->advance($Transaction->Operation);
         fread($server, 8192);
         fwrite($server, $complete('BEGIN'));
         $Database->advance($Transaction->Operation);

         return [$Database, $Transaction, $server];
      };
      // ! Drive one savepoint statement to completion and hand back its wire text.
      $settle = static function (SQL $Database, $Operation, $server, string $tag) use ($complete): string {
         $Database->advance($Operation);
         $wire = fread($server, 8192);
         fwrite($server, $complete($tag));
         $Database->advance($Operation);

         return (string) $wire;
      };
      $stack = static fn (Transaction $Transaction): array =>
         (new ReflectionProperty($Transaction, 'savepoints'))->getValue($Transaction);

      // # A named rollback keeps its target and drops everything above it
      //   ROLLBACK TO SAVEPOINT destroys every savepoint established after the
      //   target and keeps the target itself. Retiring one level left the exact
      //   inverse on the stack: the target gone, the destroyed names still live.
      [$Database, $Transaction, $server] = $begin();
      $settle($Database, $Transaction->save('outer'), $server, 'SAVEPOINT');
      $settle($Database, $Transaction->save('inner'), $server, 'SAVEPOINT');

      $wire = $settle($Database, $Transaction->rollback('outer'), $server, 'ROLLBACK');

      yield assert(
         assertion: $stack($Transaction) === ['outer']
            && $Transaction->depth === 2
            && str_contains($wire, "ROLLBACK TO SAVEPOINT \"outer\"\0"),
         description: 'Rolling back to the outer savepoint leaves only the outer savepoint live'
      );

      // # The next unnamed teardown then names a savepoint that still exists
      //   This is the defect's payload: it used to name `inner`, which the
      //   server had already destroyed, and the failure was reported after the
      //   application had explicitly rolled its own write back.
      $wire = $settle($Database, $Transaction->rollback(), $server, 'ROLLBACK');

      yield assert(
         assertion: str_contains($wire, "ROLLBACK TO SAVEPOINT \"outer\"\0")
            && $stack($Transaction) === []
            && $Transaction->depth === 1,
         description: 'An unnamed rollback targets a savepoint the server still has'
      );

      fclose($server);
      $Database->Connection->disconnect();

      // # A duplicated name resolves to the most recent one
      //   Both engines target the newest savepoint of that name. Searching
      //   forwards would find the oldest and truncate the stack far below what
      //   the server destroyed, discarding savepoints that are still live.
      [$Database, $Transaction, $server] = $begin();
      $settle($Database, $Transaction->save('a'), $server, 'SAVEPOINT');
      $settle($Database, $Transaction->save('a'), $server, 'SAVEPOINT');
      $settle($Database, $Transaction->save('b'), $server, 'SAVEPOINT');

      $settle($Database, $Transaction->rollback('a'), $server, 'ROLLBACK');

      yield assert(
         assertion: $stack($Transaction) === ['a', 'a'] && $Transaction->depth === 3,
         description: 'A duplicated name rolls back to the newest of them, not the oldest'
      );

      fclose($server);
      $Database->Connection->disconnect();

      // # RELEASE destroys its target too, so the stack truncates below it
      [$Database, $Transaction, $server] = $begin();
      $settle($Database, $Transaction->save('outer'), $server, 'SAVEPOINT');
      $settle($Database, $Transaction->save('inner'), $server, 'SAVEPOINT');

      $wire = $settle($Database, $Transaction->release('outer'), $server, 'RELEASE');

      yield assert(
         assertion: $stack($Transaction) === []
            && $Transaction->depth === 1
            && str_contains($wire, "RELEASE SAVEPOINT \"outer\"\0"),
         description: 'Releasing the outer savepoint drops it and everything above it'
      );

      fclose($server);
      $Database->Connection->disconnect();

      // # A name the stack no longer carries is refused, not guessed
      //   An earlier teardown may have destroyed it. Without the lookup this
      //   read `$this->savepoints[-1]` and raised ErrorException out of the
      //   boot handler instead of returning a failed Operation.
      [$Database, $Transaction, $server] = $begin();

      $Missing = $Transaction->rollback('never-created');

      yield assert(
         assertion: $Missing->error === 'SQL transaction savepoint is not available.',
         description: 'Rolling back to an unknown savepoint fails instead of raising'
      );

      $settle($Database, $Transaction->save('only'), $server, 'SAVEPOINT');
      $settle($Database, $Transaction->release('only'), $server, 'RELEASE');
      $Gone = $Transaction->release('only');

      yield assert(
         assertion: $Gone->error === 'SQL transaction savepoint is not active.'
            && $stack($Transaction) === [],
         description: 'Releasing an already-released savepoint fails instead of raising'
      );

      fclose($server);
      $Database->Connection->disconnect();
   }
);
