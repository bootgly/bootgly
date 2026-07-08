<?php

use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;
use function fclose;
use function fread;
use function is_resource;
use function stream_set_blocking;
use function stream_socket_pair;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Database\Operation\OperationStates;
use Bootgly\ADI\Databases\SQL\Config;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL;


return new Specification(
   description: 'MySQL: transport failures clear the FIFO and disconnect instead of pinning the pool',
   test: function () {
      // # Peer close while reading — the wire head and the queued sibling both fail
      [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($server, false);

      $Config = new Config(['driver' => 'mysql', 'secure' => ['mode' => 'disable']]);
      $Connection = new Connection($Config);
      $Connection->attach($client);
      $MySQL = new MySQL($Config, $Connection);

      $First = $MySQL->query('UPDATE a SET x = 1');
      $MySQL->advance($First);
      fread($server, 8192);

      $Second = $MySQL->query('UPDATE b SET x = 2');
      $MySQL->advance($Second);

      yield assert(
         assertion: $MySQL->check() && $First->finished === false,
         description: 'Two commands are queued before the transport dies'
      );

      fclose($server);
      $MySQL->advance($Second);

      yield assert(
         assertion: $First->finished && $First->error === 'MySQL socket closed.'
            && $Second->finished && $Second->error === 'MySQL socket closed.'
            && $First->quarantine && $Second->quarantine,
         description: 'A peer close fails the wire head and every queued sibling'
      );

      yield assert(
         assertion: $MySQL->check() === false
            && $MySQL->drain() === [$First]
            && $Connection->connected === false
            && is_resource($Connection->socket) === false,
         description: 'The FIFO empties, completed siblings drain and the connection disconnects'
      );

      // # Write failure — the peer is gone before the command is flushed
      [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($server, false);

      $Connection = new Connection($Config);
      $Connection->attach($client);
      $MySQL = new MySQL($Config, $Connection);
      fclose($server);

      $Broken = $MySQL->query('UPDATE c SET x = 3');
      $MySQL->advance($Broken);

      yield assert(
         assertion: $Broken->finished && $Broken->error === 'MySQL socket write failed.'
            && $Broken->quarantine
            && $MySQL->check() === false
            && $Connection->connected === false,
         description: 'A write failure aborts the command and releases the FIFO'
      );

      // # Socket destroyed underneath a queued command
      [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);

      $Connection = new Connection($Config);
      $Connection->attach($client);
      $MySQL = new MySQL($Config, $Connection);
      fclose($client);

      $Lost = $MySQL->query('UPDATE d SET x = 4');
      $Lost->state = OperationStates::Querying;
      $MySQL->advance($Lost);

      yield assert(
         assertion: $Lost->finished && $Lost->error === 'MySQL socket is not available.'
            && $MySQL->check() === false
            && $Connection->connected === false,
         description: 'A vanished socket aborts instead of leaving the operation queued'
      );

      fclose($server);
   }
);
