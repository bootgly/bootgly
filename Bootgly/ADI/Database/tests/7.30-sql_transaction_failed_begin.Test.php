<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;


return new Test(
   description: 'Database: a transaction whose BEGIN never opened is not a transaction',
   test: function () {
      // ! A complete backend answer: CommandComplete then ReadyForQuery.
      $complete = static function (string $command): string {
         $command = "{$command}\0";

         return 'C' . pack('N', strlen($command) + 4) . $command . 'Z' . pack('N', 5) . 'I';
      };
      /**
       * Opens a pooled database over a socketpair, with the peer alongside.
       *
       * @return array{SQL, resource}
       */
      $open = static function (): array {
         [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         stream_set_blocking($client, false);
         stream_set_blocking($server, false);

         $Database = new SQL(['timeout' => 30.0, 'pool' => ['min' => 0, 'max' => 1]]);
         $Database->Connection->attach($client);

         return [$Database, $server];
      };

      // # A withdrawn BEGIN leaves nothing to write into
      //   `depth` is set the moment the statement is composed, long before the
      //   server sees it. A transaction that trusted only that would report
      //   itself open on a connection where no transaction was ever started,
      //   and every statement the caller ran would land in autocommit — then
      //   survive the rollback the caller asked for, with no error anywhere.
      [$Database, $server] = $open();

      $Transaction = $Database->begin();
      $Begin = $Transaction->Operation;

      $composed = $Begin->state->name;

      $Database->cancel($Begin);

      $Write = $Transaction->query('INSERT INTO t (id) VALUES (1)');
      $Rolled = $Transaction->rollback();

      yield assert(
         assertion: $composed === 'Queued'
            && $Begin->state->name === 'Failed'
            && $Write->error === 'SQL transaction is not active.'
            && $Rolled->error === 'SQL transaction is not active.',
         description: 'A transaction whose BEGIN was withdrawn refuses further work'
      );

      fclose($server);
      $Database->Connection->disconnect();

      // # …but a statement that fails INSIDE an open transaction must not
      //   close it. The rollback is exactly what the caller needs at that
      //   point, and it is gated on the same check — so the check has to ask
      //   about the BEGIN, not about whatever ran last.
      [$Database, $server] = $open();

      $Transaction = $Database->begin();
      $Begin = $Transaction->Operation;

      $Database->advance($Begin);
      fread($server, 8192);
      fwrite($server, $complete('BEGIN'));
      $Database->advance($Begin);

      $opened = $Begin->finished && $Begin->error === null;

      // @ An inner statement the caller withdraws before it is written.
      $Inner = $Transaction->query('INSERT INTO t (id) VALUES (2)');
      $Database->cancel($Inner);

      $Rolled = $Transaction->rollback();

      yield assert(
         assertion: $opened
            && $Inner->state->name === 'Failed'
            && $Rolled->error === null
            && str_contains($Rolled->SQL, 'ROLLBACK'),
         description: 'A failed statement inside an open transaction still allows the rollback'
      );

      fclose($server);
      $Database->Connection->disconnect();
   }
);
