<?php

use function fclose;
use function is_resource;
use function stream_socket_get_name;
use function stream_socket_server;
use function strrpos;
use function substr;

use Bootgly\ACI\Events\Readiness;
use Bootgly\ACI\Events\Scheduler;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Databases\SQL\Config;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Database\ConnectionStates;


return new Specification(
   description: 'Database: Connection opens non-blocking TCP socket',
   test: function () {
      $errorCode = 0;
      $error = '';
      $server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $error);

      yield assert(
         assertion: is_resource($server),
         description: 'Local TCP server is available for connect test'
      );

      if (is_resource($server) === false) {
         return 'Local TCP server unavailable';
      }

      $name = stream_socket_get_name($server, false);
      if ($name === false) {
         fclose($server);

         return 'Local TCP server name unavailable';
      }

      $separator = strrpos($name, ':');
      $port = $separator === false ? 0 : (int) substr($name, $separator + 1);
      $Config = new Config([
         'host' => '127.0.0.1',
         'port' => $port,
         'timeout' => 1,
      ]);
      $Connection = new Connection($Config);
      $Readiness = $Connection->connect();

      yield assert(
         assertion: $Readiness instanceof Readiness && $Readiness->flag === Scheduler::SCHEDULE_WRITE,
         description: 'Non-blocking connect returns write readiness'
      );

      yield assert(
         assertion: is_resource($Connection->socket) && $Connection->state === ConnectionStates::Connecting,
         description: 'Connection stores socket in connecting state'
      );

      $Connection->disconnect();
      fclose($server);
   }
);
