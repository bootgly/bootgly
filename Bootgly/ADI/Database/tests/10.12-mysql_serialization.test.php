<?php

use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;
use function fclose;
use function fread;
use function fwrite;
use function pack;
use function stream_set_blocking;
use function stream_socket_pair;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Database\Operation\OperationStates;
use Bootgly\ADI\Databases\SQL\Config;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL;


return new Specification(
   description: 'MySQL: request-response FIFO — only the head owns the wire',
   test: function () {
      [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($server, false);

      $Config = new Config(['driver' => 'mysql', 'secure' => ['mode' => 'disable']]);
      $Connection = new Connection($Config);
      $Connection->attach($client);
      $MySQL = new MySQL($Config, $Connection);

      $ok = "\x00\x01\x00" . pack('v', 0) . pack('v', 0);

      // # Head goes out; the sibling stays queued
      $First = $MySQL->query('UPDATE a SET x = 1');
      $MySQL->advance($First);

      yield assert(
         assertion: fread($server, 8192) === "\x13\x00\x00\x00\x03UPDATE a SET x = 1"
            && $First->state === OperationStates::Reading,
         description: 'The first command hits the wire immediately'
      );

      $Second = $MySQL->query('UPDATE b SET x = 2');
      $MySQL->advance($Second);

      yield assert(
         assertion: fread($server, 8192) === ''
            && $Second->state === OperationStates::Querying
            && $MySQL->check(),
         description: 'The queued sibling writes nothing while the head is on the wire'
      );

      // @ Completing the head through the SIBLING advance (read pumping)
      fwrite($server, $MySQL->Encoder->frame($ok, 1));
      $MySQL->advance($Second);

      yield assert(
         assertion: $First->finished && $First->error === null
            && $First->Result?->status === 'UPDATE 1',
         description: 'A sibling advance pumps the shared read stream for the head'
      );

      yield assert(
         assertion: fread($server, 8192) === "\x13\x00\x00\x00\x03UPDATE b SET x = 2"
            && $Second->state === OperationStates::Reading,
         description: 'The next queued command is written when the head completes'
      );

      yield assert(
         assertion: $MySQL->drain() === [$First] && $MySQL->drain() === [],
         description: 'Operations completed by sibling pumping surface through drain()'
      );

      fwrite($server, $MySQL->Encoder->frame($ok, 1));
      $MySQL->advance($Second);

      yield assert(
         assertion: $Second->finished && $MySQL->check() === false,
         description: 'The queue empties when the last command resolves'
      );

      fclose($server);
      $Connection->disconnect();
   }
);
