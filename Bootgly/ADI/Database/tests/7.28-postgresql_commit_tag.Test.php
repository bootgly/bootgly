<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Operation\OperationStates;
use Bootgly\ADI\Databases\SQL;


return new Test(
   description: 'PostgreSQL: a COMMIT the backend answers with ROLLBACK is not a successful commit',
   test: function () {
      $complete = static function (string $command): string {
         $command = "{$command}\0";

         return 'C' . pack('N', strlen($command) + 4) . $command . 'Z' . pack('N', 5) . 'I';
      };

      /**
       * Runs one statement and answers it with the given CommandComplete tag.
       *
       * @return array{SQL, \Bootgly\ADI\Databases\SQL\Operation, resource}
       */
      $answer = static function (string $sql, string $tag) use ($complete): array {
         [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         stream_set_blocking($client, false);
         stream_set_blocking($server, false);

         $Database = new SQL(['timeout' => 30.0, 'pool' => ['min' => 0, 'max' => 1]]);
         $Database->Connection->attach($client);

         $Operation = $Database->query($sql);
         $Database->advance($Operation);
         fread($server, 8192);
         fwrite($server, $complete($tag));
         $Database->advance($Operation);

         return [$Database, $Operation, $server];
      };

      // # A COMMIT answered with ROLLBACK
      //   The backend reports an aborted transaction block only in this tag —
      //   no ErrorResponse follows it. Storing the tag without comparing it to
      //   the statement resolved the operation as a successful commit while
      //   the server had discarded every write in the transaction.
      [$Database, $Operation, $server] = $answer('COMMIT', 'ROLLBACK');

      yield assert(
         assertion: $Operation->state === OperationStates::Failed
            && $Operation->error === 'SQL transaction was rolled back by the server: a statement inside it had failed.'
            && $Operation->status === 'ROLLBACK',
         description: 'A COMMIT answered with the ROLLBACK tag fails with the reason'
      );

      fclose($server);
      $Database->Connection->disconnect();

      // # A COMMIT answered with COMMIT is untouched
      [$Database, $Operation, $server] = $answer('COMMIT', 'COMMIT');

      yield assert(
         assertion: $Operation->state === OperationStates::Finished
            && $Operation->error === null
            && $Operation->status === 'COMMIT',
         description: 'An ordinary commit still succeeds'
      );

      fclose($server);
      $Database->Connection->disconnect();

      // # An explicit ROLLBACK answered with ROLLBACK is untouched
      //   The tag matches the statement, so nothing about it is a surprise.
      [$Database, $Operation, $server] = $answer('ROLLBACK', 'ROLLBACK');

      yield assert(
         assertion: $Operation->state === OperationStates::Finished
            && $Operation->error === null
            && $Operation->status === 'ROLLBACK',
         description: 'An explicit rollback still succeeds'
      );

      fclose($server);
      $Database->Connection->disconnect();

      // # Ordinary statements keep their own tags
      //   The check is keyed on the statement, not on the tag alone: a SELECT
      //   is never a commit and must never be judged as one.
      [$Database, $Operation, $server] = $answer('SELECT 1 AS v', 'SELECT 1');

      yield assert(
         assertion: $Operation->error === null
            && $Operation->status === 'SELECT 1'
            && $Operation->affected === 1,
         description: 'A statement that is not a commit is unaffected'
      );

      fclose($server);
      $Database->Connection->disconnect();
   }
);
