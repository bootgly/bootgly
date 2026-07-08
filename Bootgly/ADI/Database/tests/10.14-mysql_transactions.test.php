<?php

use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;
use function count;
use function fclose;
use function fread;
use function fwrite;
use function pack;
use function str_contains;
use function stream_set_blocking;
use function stream_socket_pair;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Database\Operation\OperationStates;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL\Encoder;


return new Specification(
   description: 'MySQL: transactions pin one pooled connection and support savepoints',
   test: function () {
      [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($server, false);

      $Encoder = new Encoder;
      $ok = $Encoder->frame("\x00\x00\x00" . pack('v', 0) . pack('v', 0), 1);

      $Database = new SQL([
         'driver' => 'mysql',
         'pool' => [
            'min' => 0,
            'max' => 1,
         ],
         'secure' => ['mode' => 'disable'],
      ]);
      $Database->Connection->attach($client);

      $Transaction = $Database->begin();
      $Operation = $Transaction->Operation;

      yield assert(
         assertion: $Operation !== null && $Operation->lock,
         description: 'SQL begin creates a lock operation'
      );

      $Database->advance($Operation);
      $wire = (string) fread($server, 8192);

      yield assert(
         assertion: str_contains($wire, "\x03BEGIN"),
         description: 'Begin operation sends COM_QUERY BEGIN on the wire'
      );

      fwrite($server, $ok);
      $Database->advance($Operation);

      yield assert(
         assertion: $Operation->state === OperationStates::Finished
            && count($Database->Pool->busy) === 1
            && $Database->Pool->idle === [],
         description: 'Pool keeps the transaction connection locked after BEGIN'
      );

      $Outside = $Database->query('SELECT 1 AS outside');

      yield assert(
         assertion: $Outside->state === OperationStates::Pending,
         description: 'A normal query cannot borrow the locked transaction connection'
      );

      $Inside = $Transaction->query('SELECT 2 AS inside');

      yield assert(
         assertion: $Inside->Connection === $Operation->Connection,
         description: 'Transaction query is pinned to the BEGIN connection'
      );

      $Database->advance($Inside);
      $wire = (string) fread($server, 8192);

      yield assert(
         assertion: str_contains($wire, "\x03SELECT 2 AS inside"),
         description: 'Pinned transaction query writes through the same connection'
      );

      // @ Result set — column count, definition, row, terminal EOF (classic)
      $eof = $Encoder->frame("\xFE" . pack('v', 0) . pack('v', 0), 4);
      fwrite($server, $Encoder->frame("\x01", 1));
      fwrite($server, $Encoder->frame(
         "\x03def\x02db\x01t\x01t\x06inside\x06inside\x0C" . pack('v', 45) . pack('V', 11) . "\x03" . pack('v', 0) . "\x00\x00\x00",
         2
      ));
      fwrite($server, $Encoder->frame("\xFE" . pack('v', 0) . pack('v', 0), 3));
      fwrite($server, $Encoder->frame("\x012", 4));
      fwrite($server, $eof);
      $Database->advance($Inside);

      yield assert(
         assertion: $Inside->finished && $Inside->Result?->rows === [['inside' => 2]],
         description: 'The pinned query resolves through the MySQL text protocol'
      );

      $Save = $Transaction->save();
      $Database->advance($Save);
      $wire = (string) fread($server, 8192);

      yield assert(
         assertion: $Transaction->depth === 2 && str_contains($wire, 'SAVEPOINT `bootgly_0`'),
         description: 'Savepoint uses the MySQL backtick quoting'
      );

      fwrite($server, $ok);
      $Database->advance($Save);

      $Rollback = $Transaction->rollback();
      $Database->advance($Rollback);
      $wire = (string) fread($server, 8192);

      yield assert(
         assertion: $Transaction->depth === 1 && str_contains($wire, 'ROLLBACK TO SAVEPOINT `bootgly_0`'),
         description: 'Nested rollback targets the latest generated savepoint'
      );

      fwrite($server, $ok);
      $Database->advance($Rollback);

      $Commit = $Transaction->commit();

      yield assert(
         assertion: $Commit->unlock,
         description: 'Top-level commit unlocks the transaction connection'
      );

      $Database->advance($Commit);
      $wire = (string) fread($server, 8192);

      yield assert(
         assertion: str_contains($wire, "\x03COMMIT"),
         description: 'Commit operation sends COM_QUERY COMMIT on the wire'
      );

      fwrite($server, $ok);
      $Database->advance($Commit);

      yield assert(
         assertion: $Commit->state === OperationStates::Finished && $Transaction->depth === 0,
         description: 'Commit finishes the transaction state'
      );

      fclose($server);
      $Database->Connection->disconnect();
   }
);
