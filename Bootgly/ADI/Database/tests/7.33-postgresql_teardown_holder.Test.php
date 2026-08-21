<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Databases\SQL\Config;
use Bootgly\ADI\Databases\SQL\Drivers\PostgreSQL;


return new Test(
   description: 'PostgreSQL: a session teardown fails the batch that was half-written on it',
   test: function () {
      $connect = function (): array {
         [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         stream_set_blocking($client, false);
         stream_set_blocking($server, false);

         $Config = new Config(['driver' => 'pgsql', 'statements' => 256, 'timeout' => 5.0]);
         $Connection = new Connection($Config);
         $Connection->attach($client);

         return [new PostgreSQL($Config, $Connection), $Connection, $server];
      };

      $payload = str_repeat('x', 512 * 1024);

      // # The teardown reaches the operation that holds the write stream
      //   PostgreSQL queues an operation only once its batch is whole on the
      //   wire, so a half-written one is in neither the pipeline nor
      //   completed[]. Nothing in abort()'s loop reaches it, and clearing the
      //   pointer alone left the caller unfinished and errorless on a session
      //   that no longer exists.
      [$PostgreSQL, $Connection, $server] = $connect();

      $Read = $PostgreSQL->query('SELECT 1 AS a');
      $PostgreSQL->advance($Read);

      $Holder = $PostgreSQL->query('SELECT length($1) AS n', [$payload]);
      $PostgreSQL->advance($Holder);
      $stalled = strlen($Holder->write);

      // @ The peer goes away; the next read on the pipelined sibling routes
      //   through the teardown.
      fclose($server);
      $PostgreSQL->advance($Read);

      yield assert(
         assertion: $stalled > 0 && $Read->finished && $Read->error !== null
            && $Connection->connected === false,
         description: 'The teardown ran: the pipelined sibling failed and the transport is down'
      );

      yield assert(
         assertion: $Holder->finished && $Holder->error === $Read->error,
         description: 'The half-written batch fails with the same cause as every pipelined sibling, found: '
            . json_encode([$Holder->finished, $Holder->error])
      );

      yield assert(
         assertion: $Holder->quarantine,
         description: 'It counts against the pool health like every other casualty of the session'
      );

      // ? Its buffer must not survive the socket it was being written to: the
      //   tail of a message would be read as a message of its own.
      yield assert(
         assertion: $Holder->write === '',
         description: 'The batch buffer dies with the session'
      );

      // ? Whoever tears the session down hands the casualties back through
      //   drain(), which is how the pool learns to release their connection.
      $Completed = $PostgreSQL->drain();

      yield assert(
         assertion: in_array($Holder, $Completed, true),
         description: 'The holder is handed back through drain(), like the pipelined siblings'
      );

      // # The operation the teardown was called for is not handed to itself
      //   Its caller already holds it — pushing it into completed[] would have
      //   the pool release one operation twice.
      [$PostgreSQL, $Connection, $server] = $connect();

      $Holder = $PostgreSQL->query('SELECT length($1) AS n', [$payload]);
      $PostgreSQL->advance($Holder);

      // @ Its own next flush finds the dead socket, so the teardown is raised
      //   for the very operation that holds the stream.
      fclose($server);
      $PostgreSQL->advance($Holder);

      yield assert(
         assertion: $Holder->finished && in_array($Holder, $PostgreSQL->drain(), true) === false,
         description: 'The operation that carried the teardown is failed but never queued back to itself, found: '
            . json_encode([$Holder->finished, $Holder->error])
      );

      $Connection->disconnect();
   }
);
