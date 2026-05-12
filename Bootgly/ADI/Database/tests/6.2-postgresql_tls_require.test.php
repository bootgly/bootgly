<?php

use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;
use function fclose;
use function fread;
use function fwrite;
use function stream_set_blocking;
use function stream_socket_pair;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Databases\SQL\Config;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Databases\SQL\Operation;
use Bootgly\ADI\Database\OperationStates;
use Bootgly\ADI\Databases\SQL\Drivers\PostgreSQL;


return new Specification(
   description: 'Database: PostgreSQL TLS require fails when server refuses SSL',
   test: function () {
      [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($server, false);

      $Config = new Config([
         'secure' => [
            'mode' => 'require',
         ],
      ]);
      $Connection = new Connection($Config);
      $Connection->attach($client);
      $PostgreSQL = new PostgreSQL($Config, $Connection);
      $Operation = new Operation($Connection, 'SELECT 1');
      $Operation->state = OperationStates::Connecting;

      $PostgreSQL->advance($Operation);
      fread($server, 8192);
      fwrite($server, 'N');
      $PostgreSQL->advance($Operation);

      yield assert(
         assertion: $Operation->state === OperationStates::Failed && $Operation->error === 'PostgreSQL server refused required TLS.',
         description: 'State machine fails when TLS is required but refused'
      );

      fclose($server);
      $Connection->disconnect();
   }
);
