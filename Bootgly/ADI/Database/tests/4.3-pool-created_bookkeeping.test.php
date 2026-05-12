<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Databases\SQL\Operation;


return new Specification(
   description: 'Database: Pool created counter tracks owned connections only once',
   test: function () {
      [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($server, false);

      $Database = new SQL([
         'pool' => [
            'min' => 0,
            'max' => 2,
         ],
      ]);
      $Database->Connection->attach($client);
      $Operation = $Database->query('SELECT 1 AS value');

      yield assert(
         assertion: $Database->Pool->created === 1 && count($Database->Pool->busy) === 1,
         description: 'Pool counts one owned busy connection'
      );

      $Stray = new Connection($Database->Config);
      $StrayOperation = new Operation($Stray, 'SELECT 2 AS value');
      $StrayOperation->fail('stray');
      $Database->Pool->release($StrayOperation);

      yield assert(
         assertion: $Database->Pool->created === 1 && count($Database->Pool->busy) === 1,
         description: 'Releasing an untracked connection does not decrement created count'
      );

      $Operation->fail('boom');
      $Database->Connection->disconnect();
      $Database->Pool->release($Operation);

      yield assert(
         assertion: $Database->Pool->created === 0 && $Database->Pool->idle === [] && $Database->Pool->busy === [],
         description: 'Closed failed owned connection is removed from bookkeeping exactly once'
      );

      $Database->Pool->release($Operation);

      yield assert(
         assertion: $Database->Pool->created === 0,
         description: 'Repeated release of the same failed connection does not underflow created count'
      );

      fclose($server);
   }
);
