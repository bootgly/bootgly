<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Operation;


return new Test(
   description: 'Pool: releasing an unlock operation frees the reservation even while the driver still holds a sibling',
   test: function () {
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

      $Pool = $Database->Pool;
      $ok = "\x00\x01\x00" . pack('v', 0) . pack('v', 0);
      $frame = fn (string $payload, int $sequence): string =>
         substr(pack('V', strlen($payload)), 0, 3) . chr($sequence) . $payload;

      // ! The shape a transaction leaves behind: one pinned, reserved connection.
      $Begin = new Operation(null, 'BEGIN', [], 30.0);
      $Begin->lock = true;
      $Pool->assign($Begin);
      $Database->advance($Begin);
      fread($server, 8192);

      fwrite($server, $frame($ok, 1));
      $Database->advance($Begin);

      $Connection = $Begin->Connection;

      yield assert(
         assertion: $Begin->finished
            && $Connection !== null
            && count($Pool->busy) === 1
            && $Pool->idle === [],
         description: 'The reserved connection stays out of the idle set after BEGIN'
      );

      // ! The teardown owns the wire, and a sibling queues up behind it.
      $Teardown = new Operation($Connection, 'ROLLBACK', [], 30.0);
      $Teardown->unlock = true;
      $Pool->assign($Teardown);
      $Database->advance($Teardown);
      fread($server, 8192);

      $Later = new Operation($Connection, 'SELECT 1', [], 30.0);
      $Pool->assign($Later);
      $Database->advance($Later);

      yield assert(
         assertion: $Teardown->finished === false && $Later->finished === false,
         description: 'The teardown is on the wire with one sibling queued behind it'
      );

      // @ The teardown completes while the driver still holds the sibling.
      fwrite($server, $frame($ok, 1));
      $Database->advance($Teardown);

      yield assert(
         assertion: $Teardown->finished && $Teardown->error === null,
         description: 'The teardown completes on the wire'
      );

      // @ The sibling completes afterwards and hands the connection back.
      fwrite($server, $frame($ok, 1));
      $Database->advance($Later);

      yield assert(
         assertion: $Later->finished,
         description: 'The queued sibling completes too'
      );

      yield assert(
         assertion: count($Pool->idle) === 1 && $Pool->busy === [],
         description: 'The reservation the teardown carried is honoured, so the connection returns to the pool'
      );

      fclose($server);
      $Database->Connection->disconnect();
   }
);
