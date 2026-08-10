<?php


use Bootgly\ACI\Events\Readiness;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL\Config;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Databases\SQL\Operation;
use Bootgly\ADI\Database\Operation\OperationStates;
use Bootgly\ADI\Databases\SQL\Drivers\PostgreSQL;


return new Test(
   description: 'Database: PostgreSQL TLS accept enters handshake state',
   test: function () {
      [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($server, false);

      $Config = new Config;
      $Connection = new Connection($Config);
      $Connection->attach($client);
      $PostgreSQL = new PostgreSQL($Config, $Connection);
      $Operation = new Operation($Connection, 'SELECT 1');
      $Operation->state = OperationStates::Connecting;

      $PostgreSQL->advance($Operation);
      fread($server, 8192);
      fwrite($server, 'S');
      $PostgreSQL->advance($Operation);

      yield assert(
         assertion: $Operation->state === OperationStates::SSLHandshake && $Operation->Readiness instanceof Readiness,
         description: 'State machine waits for TLS handshake after server accepts SSL'
      );

      fclose($server);
      $Connection->disconnect();
   }
);
