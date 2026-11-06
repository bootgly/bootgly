<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Database\Operation\OperationStates;
use Bootgly\ADI\Databases\SQL\Config;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL;


return new Test(
   description: 'MySQL: startup session parameters are refused at config time, never connected without them',
   test: function () {
      // ! A local listener: an accepted socket proves the driver dialed.
      $Server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

      if ($Server === false) {
         throw new RuntimeException("MySQL session parameters: listener failed: {$error}.");
      }

      $address = (string) stream_socket_get_name($Server, false);
      $port = (int) substr($address, (int) strrpos($address, ':') + 1);
      $open = static function (array $parameters) use ($port): array {
         $Config = new Config([
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'port' => $port,
            'timeout' => 1.0,
            'secure' => ['mode' => 'disable'],
            'parameters' => $parameters,
         ]);
         $Connection = new Connection($Config);

         return [new MySQL($Config, $Connection), $Connection];
      };

      // # Refused — the config itself fails loud, so no driver is ever built
      $refusal = '';

      try {
         $open(['sql_mode' => 'ANSI']);
      }
      catch (InvalidArgumentException $Exception) {
         $refusal = $Exception->getMessage();
      }

      $Accepted = @stream_socket_accept($Server, 0.2);

      yield assert(
         assertion: $refusal === 'Database `parameters` are PostgreSQL only: the mysql driver has no startup session parameters, so set the session after connecting instead.'
            && $Accepted === false,
         description: "A non-empty `parameters` is refused at config time and nothing is dialed — {$refusal}"
      );

      // # Control — without parameters the same driver dials
      [$MySQL, $Connection] = $open([]);
      $Operation = $MySQL->query('SELECT 1');
      $MySQL->advance($Operation);
      $Accepted = @stream_socket_accept($Server, 1.0);

      yield assert(
         assertion: $Operation->error === null && $Operation->state === OperationStates::Connecting && is_resource($Accepted),
         description: 'An empty `parameters` map dials as before'
      );

      if (is_resource($Accepted)) {
         fclose($Accepted);
      }
      $Connection->disconnect();
      fclose($Server);
   }
);
