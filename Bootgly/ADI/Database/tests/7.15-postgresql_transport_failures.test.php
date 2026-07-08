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
use Bootgly\ADI\Databases\SQL;


return new Specification(
   description: 'Database: PostgreSQL transport failures clear the pipeline and drop the connection',
   test: function () {
      // # Peer close while reading — the head and the pipelined sibling both fail
      [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($server, false);

      $Database = new SQL([
         'pool' => [
            'min' => 0,
            'max' => 1,
         ],
      ]);
      $Database->Connection->attach($client);

      $First = $Database->query('SELECT 1 AS value');
      $Database->advance($First);
      fread($server, 8192);
      $Second = $Database->query('SELECT 2 AS value');
      $Database->advance($Second);
      fread($server, 8192);

      $Protocol = $First->Protocol;

      yield assert(
         assertion: $Protocol !== null && $Protocol->check(),
         description: 'Two commands are pipelined before the transport dies'
      );

      fclose($server);
      $Database->advance($First);

      yield assert(
         assertion: $First->finished && $First->error === 'PostgreSQL socket closed.'
            && $Second->finished && $Second->error === 'PostgreSQL socket closed.'
            && $First->quarantine && $Second->quarantine,
         description: 'A peer close fails the head and every pipelined sibling'
      );

      yield assert(
         assertion: $Protocol->check() === false
            && $Database->Connection->connected === false
            && is_resource($Database->Connection->socket) === false
            && $Database->Pool->busy === []
            && $Database->Pool->idle === []
            && $Database->Pool->created === 0,
         description: 'The pipeline empties and the pool drops the dead connection'
      );

      // # Write failure — the peer is gone before the command is flushed
      [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($server, false);

      $Database = new SQL([
         'pool' => [
            'min' => 0,
            'max' => 1,
         ],
      ]);
      $Database->Connection->attach($client);
      fclose($server);

      $Broken = $Database->query('SELECT 3 AS value');
      $Database->advance($Broken);

      yield assert(
         assertion: $Broken->finished && $Broken->error === 'PostgreSQL socket write failed.'
            && $Broken->quarantine
            && $Broken->Protocol?->check() === false
            && $Database->Connection->connected === false
            && $Database->Pool->created === 0,
         description: 'A write failure aborts the command and the pool drops the connection'
      );
   }
);
