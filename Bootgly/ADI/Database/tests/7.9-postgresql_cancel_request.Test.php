<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Database\Operation\OperationStates;
use Bootgly\ADI\Databases\SQL\Drivers\PostgreSQL;
use Bootgly\ADI\Databases\SQL\Drivers\PostgreSQL\Encoder;


return new Test(
   description: 'Database: PostgreSQL cancellation uses BackendKeyData on a separate socket',
   test: function () {
      $errorCode = 0;
      $error = '';
      $server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $error);

      yield assert(
         assertion: is_resource($server),
         description: 'Local TCP server is available for cancel request test'
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
      $Database = new SQL([
         'host' => '127.0.0.1',
         'port' => $port,
         'timeout' => 1,
      ]);
      // ! A cancel request names the backend, so the driver sends one only
      //   for the batch the backend runs: the FIFO head, whole on the wire.
      [$client, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($peer, false);
      $Database->Connection->attach($client);

      $Operation = $Database->query('SELECT pg_sleep(10)');
      $Database->advance($Operation);
      fread($peer, 8192);
      $Driver = $Operation->Protocol;

      if ($Driver instanceof PostgreSQL) {
         $Driver->identify(123, 456);
      }

      // ! The driver's own door. This case is about what PostgreSQL puts on the
      //   side channel, and the pool refuses to cancel an operation that never
      //   reached the wire — a cancel request names a backend, not a statement.
      $Driver->cancel($Operation);
      $cancel = stream_socket_accept($server, 1);

      yield assert(
         assertion: is_resource($cancel),
         description: 'Cancel request opens a separate TCP connection'
      );

      if (is_resource($cancel) === false) {
         fclose($server);

         return 'Cancel request connection was not accepted';
      }

      $Encoder = new Encoder;
      $expected = $Encoder->encode(Encoder::CANCEL, [
         'process' => 123,
         'secret' => 456,
      ]);

      yield assert(
         assertion: $Operation->state !== OperationStates::Failed && $Operation->cancelled && fread($cancel, 8192) === $expected,
         description: 'Cancel request sends backend process and secret key'
      );

      fclose($cancel);

      $Missing = new SQL([
         'host' => '127.0.0.1',
         'port' => $port,
      ]);
      [$client, $other] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($other, false);
      $Missing->Connection->attach($client);

      $MissingOperation = $Missing->query('SELECT 1');
      $Missing->advance($MissingOperation);
      fread($other, 8192);
      $MissingOperation->Protocol?->cancel($MissingOperation);

      yield assert(
         assertion: $MissingOperation->state === OperationStates::Failed && $MissingOperation->error === 'PostgreSQL cancellation requires BackendKeyData.',
         description: 'Cancellation fails clearly before BackendKeyData is available'
      );

      fclose($peer);
      fclose($other);
      $Database->Connection->disconnect();
      $Missing->Connection->disconnect();
      fclose($server);
   }
);
