<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Pool;
use Bootgly\ADI\Databases\SQL;


return new Test(
   description: 'Database: a connection claim is settled once, however late its slot retires',
   test: function () {
      $message = static fn (string $type, string $payload): string =>
         $type . pack('N', strlen($payload) + 4) . $payload;
      $failure = static fn (string $text): string =>
         'E' . pack('N', strlen("SERROR\0C23503\0M{$text}\0\0") + 4) . "SERROR\0C23503\0M{$text}\0\0";

      /**
       * Opens a pooled database over a socketpair.
       *
       * @return array{SQL, resource}
       */
      $connect = static function () use ($message): array {
         [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         stream_set_blocking($client, false);
         stream_set_blocking($server, false);

         $Database = new SQL(['timeout' => 30.0, 'pool' => ['min' => 0, 'max' => 1]]);
         $Database->Connection->attach($client);

         return [$Database, $server];
      };

      // # A statement that fails with its ReadyForQuery in a later read
      //   The driver keeps its FIFO slot until that message arrives, so the
      //   operation reaches release() once when it fails and again when the
      //   slot retires. The second pass re-runs the compensation — by which
      //   point the connection belongs to whoever took it in between.
      [$Database, $server] = $connect();
      $Pool = $Database->Pool;
      $settled = new ReflectionProperty(Pool::class, 'settled');

      $Refused = $Database->query('INSERT INTO t (v) VALUES (1)');
      $Database->advance($Refused);
      fread($server, 8192);
      fwrite($server, $failure('violates foreign key constraint'));
      $Database->advance($Refused);

      yield assert(
         assertion: $Refused->finished
            && isset($settled->getValue($Pool)[$Refused])
            && count($Pool->idle) === 1,
         description: 'A failed operation settles its claim and gives the connection back'
      );

      // @ The trailing ReadyForQuery retires the slot, and the operation
      //   reaches release() a second time through Pool::drain().
      fwrite($server, $message('Z', 'I'));

      $Next = $Database->query('SELECT 1 AS v');
      $Database->advance($Next);

      yield assert(
         assertion: $Next->Connection !== null
            && $Pool->created === 1
            && $Pool->pending === [],
         description: 'The late retirement does not disturb the operation that took the connection'
      );

      fclose($server);
      $Database->Connection->disconnect();

      // # A retried operation may claim again
      //   The ledger records a settled claim, not a spent operation: assign()
      //   hands out a fresh connection, so the mark has to clear with it.
      [$Database, $server] = $connect();
      $Pool = $Database->Pool;

      $Retried = $Database->query('SELECT 1 AS v');
      $Database->advance($Retried);
      fread($server, 8192);
      fwrite($server, $failure('transient'));
      $Database->advance($Retried);

      $marked = isset($settled->getValue($Pool)[$Retried]);

      $Retried->retry();
      $Pool->assign($Retried);

      yield assert(
         assertion: $marked && isset($settled->getValue($Pool)[$Retried]) === false,
         description: 'Assigning a connection clears the claim it settles'
      );

      fclose($server);
      $Database->Connection->disconnect();
   }
);
