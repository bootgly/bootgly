<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Operation;


return new Test(
   description: 'Pool: an expired operation gives its connection slot back',
   test: function () {
      $connect = function (): array {
         [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         stream_set_blocking($client, false);
         stream_set_blocking($server, false);

         $Database = new SQL([
            'driver' => 'mysql',
            'secure' => ['mode' => 'disable'],
            'timeout' => 0.02,
            'pool' => [
               'min' => 0,
               'max' => 1,
            ],
         ]);
         $Database->Connection->attach($client);

         return [$Database, $server];
      };
      $ok = "\x00\x01\x00" . pack('v', 0) . pack('v', 0);
      $frame = fn (string $payload, int $sequence): string =>
         substr(pack('V', strlen($payload)), 0, 3) . chr($sequence) . $payload;

      // # No sibling — the slot comes back with the dropped connection
      [$Database, $server] = $connect();
      $Pool = $Database->Pool;
      $Slow = $Database->query('UPDATE a SET x = 1');
      $Database->advance($Slow);
      fread($server, 8192);

      usleep(30_000);
      $Database->advance($Slow);

      yield assert(
         assertion: $Slow->finished && str_contains($Slow->error ?? '', 'timed out'),
         description: 'The pool expires the operation on its elapsed deadline'
      );

      yield assert(
         assertion: $Pool->created === 0 && $Pool->busy === [] && $Pool->idle === [],
         description: 'An expired operation with no reader releases its pool slot'
      );

      yield assert(
         assertion: $Database->Connection->connected === false,
         description: 'The connection nobody can resynchronize is dropped'
      );

      fclose($server);

      // # A sibling keeps the connection legitimately busy, then gives it back
      [$Database, $server] = $connect();
      $Pool = $Database->Pool;
      $Slow = $Database->query('UPDATE a SET x = 1');
      $Sibling = new Operation(null, 'UPDATE b SET x = 2', [], 30.0);
      $Pool->assign($Sibling);
      $Database->advance($Slow);
      $Database->advance($Sibling);
      fread($server, 8192);

      usleep(30_000);
      $Database->advance($Slow);

      yield assert(
         assertion: $Pool->created === 1 && count($Pool->busy) === 1 && $Database->Connection->connected,
         description: 'A sibling still reading keeps the connection busy instead of dropping it'
      );

      // @ The abandoned response drains, then the sibling owns the wire.
      fwrite($server, $frame($ok, 1));
      $Database->advance($Sibling);

      yield assert(
         assertion: $Sibling->finished === false
            && str_contains((string) fread($server, 8192), 'UPDATE b SET x = 2'),
         description: 'The sibling writes its own command once the abandoned response is drained'
      );

      fwrite($server, $frame($ok, 1));
      $Database->advance($Sibling);

      yield assert(
         assertion: $Sibling->finished && $Sibling->error === null
            && $Pool->created === 1 && $Pool->busy === [] && count($Pool->idle) === 1,
         description: 'The last release hands the reusable connection back to the pool'
      );

      fclose($server);
      $Database->Connection->disconnect();
   }
);
