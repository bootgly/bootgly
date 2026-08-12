<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Operation\OperationStates;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Operation;


return new Test(
   description: 'Pool: an operation pinned to a connection the pool cannot provide fails instead of parking',
   test: function () {
      $connect = function (): array {
         [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         stream_set_blocking($client, false);
         stream_set_blocking($server, false);

         $Database = new SQL([
            'driver' => 'mysql',
            'secure' => ['mode' => 'disable'],
            'timeout' => 30.0,
            'pool' => [
               'min' => 0,
               'max' => 2,
            ],
         ]);
         $Database->Connection->attach($client);

         return [$Database, $server];
      };
      $ok = "\x00\x01\x00" . pack('v', 0) . pack('v', 0);
      $frame = fn (string $payload, int $sequence): string =>
         substr(pack('V', strlen($payload)), 0, 3) . chr($sequence) . $payload;

      // ! One reserved connection, exactly as a transaction pins it.
      [$Database, $server] = $connect();
      $Pool = $Database->Pool;

      $Begin = new Operation(null, 'BEGIN', [], 30.0);
      $Begin->lock = true;
      $Pool->assign($Begin);
      $Database->advance($Begin);
      fread($server, 8192);

      fwrite($server, $frame($ok, 1));
      $Database->advance($Begin);

      $Connection = $Begin->Connection;

      yield assert(
         assertion: $Begin->finished && $Connection !== null && $Pool->created === 1,
         description: 'The transaction connection is reserved and counted'
      );

      // @ The pinned connection dies out of band — a restart, an LB recycle.
      if (is_resource($Connection?->socket)) {
         fclose($Connection->socket);
      }

      $Stuck = new Operation($Connection, 'SELECT 1', [], 30.0);
      $Pool->assign($Stuck);

      yield assert(
         assertion: $Stuck->finished && $Stuck->error !== null,
         description: 'A pin no capacity can ever satisfy fails instead of waiting for one'
      );

      yield assert(
         assertion: $Pool->pending === [],
         description: 'The failed operation does not stay queued behind a connection that will never return'
      );

      yield assert(
         assertion: $Pool->created === 0 && $Pool->busy === [] && $Pool->idle === [],
         description: 'The dead connection leaves the pool bookkeeping, so its slot is reusable'
      );

      // @ The pool is usable again for a fresh, unpinned operation.
      $Fresh = new Operation(null, 'SELECT 2', [], 30.0);
      $Pool->assign($Fresh);

      yield assert(
         assertion: $Fresh->Connection !== null && $Fresh->state !== OperationStates::Pending,
         description: 'A later operation is assigned rather than parked'
      );

      fclose($server);
   }
);
