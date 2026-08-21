<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;


return new Test(
   description: 'PostgreSQL: a half-written batch nobody drives cannot hold the connection for good',
   test: function () {
      /**
       * Opens a pooled database over a socketpair whose peer never reads, so a
       * batch larger than the socket buffer can only be written in part.
       *
       * @return array{SQL, resource}
       */
      $open = static function (float $timeout): array {
         [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         stream_set_blocking($client, false);
         stream_set_blocking($server, false);

         $Database = new SQL(['timeout' => $timeout, 'pool' => ['min' => 0, 'max' => 1]]);
         $Database->Connection->attach($client);

         return [$Database, $server];
      };
      // ! Far larger than any socket buffer, so flush() cannot finish it.
      $payload = str_repeat('x', 4 * 1024 * 1024);
      // # A live holder still owns the stream — the guard must not fire

      [$Database, $Server] = $open(30.0);

      $Big = $Database->query('SELECT length($1) AS n', [$payload]);
      $Sibling = $Database->query('SELECT 1 AS v');

      $Database->advance($Big);
      $Database->advance($Sibling);

      yield assert(
         assertion: $Big->finished === false && $Sibling->finished === false
            && $Sibling->error === null && $Database->Connection->connected,
         description: 'A sibling waits behind a batch that is still within its deadline'
      );

      fclose($Server);

      // # A holder past its own deadline is reconciled instead of blocking for good

      [$Database, $Server] = $open(0.05);

      $Big = $Database->query('SELECT length($1) AS n', [$payload]);

      $Database->advance($Big);

      $unsent = strlen($Big->write);
      $held = $Big->finished === false;

      // @ Wait out the holder's deadline. Nobody advances it — that is the defect:
      //   `Pool::wait()` drives only the operation it was handed.
      $started = microtime(true);

      while (microtime(true) - $started < 0.08) {
         // ! Busy — a pending signal would cut a sleep short.
      }

      // ! Composed after the wait: `Pool::advance()` expires the operation it is
      //   handed before anything else, so a sibling built alongside the holder
      //   would die of its own deadline and never reach the driver.
      $Sibling = $Database->query('SELECT 2 AS v');
      $Database->advance($Sibling);

      yield assert(
         assertion: $unsent > 0 && $held,
         description: 'The batch really did stop half-written, found: ' . json_encode([$unsent, $held])
      );

      yield assert(
         assertion: $Big->finished && $Big->error !== null,
         description: 'The abandoned batch is finished rather than left owning the stream, found: '
            . json_encode($Big->error)
      );

      yield assert(
         assertion: $Sibling->finished
            && $Sibling->error === 'PostgreSQL connection was left half-written by an abandoned batch.'
            && $Sibling->quarantine,
         description: 'The sibling is told the real cause instead of waiting out its own deadline, found: '
            . json_encode($Sibling->error)
      );

      // ? The session cannot be resynchronised from the middle of a wire message,
      //   so the transport is dropped and the pool opens a fresh connection.
      yield assert(
         assertion: $Database->Connection->connected === false
            && $Database->Pool->created === 0,
         description: 'The connection is dropped so the pool can serve the next caller, found: '
            . json_encode([$Database->Connection->connected, $Database->Pool->created])
      );

      fclose($Server);
   }
);
