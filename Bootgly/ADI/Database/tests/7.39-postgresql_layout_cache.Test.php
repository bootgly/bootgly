<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Drivers\PostgreSQL;


return new Test(
   description: 'Database: PostgreSQL warm batch reuses the cached row layout and drops its portal Describe',
   test: function () {
      [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($server, false);

      $Database = new SQL(['driver' => 'pgsql', 'statements' => 8, 'pool' => ['min' => 0, 'max' => 1]]);
      $Database->Connection->attach($client);

      // ! Backend answer for a cold Parse+Describe(S)+Bind+Describe(P)+Execute+Sync
      $rowDescription = static function (array $columns): string {
         $payload = pack('n', count($columns));

         foreach ($columns as $name => $type) {
            $payload .= "{$name}\0" . pack('N', 0) . pack('n', 0) . pack('N', $type)
               . pack('n', 4) . pack('N', 0xFFFFFFFF) . pack('n', 0);
         }

         return 'T' . pack('N', strlen($payload) + 4) . $payload;
      };
      $parseComplete = '1' . pack('N', 4);
      $parameterPayload = pack('n', 1) . pack('N', 23);
      $parameterDescription = 't' . pack('N', strlen($parameterPayload) + 4) . $parameterPayload;
      $bindComplete = '2' . pack('N', 4);
      $dataPayload = pack('n', 1) . pack('N', 2) . '42';
      $dataRow = 'D' . pack('N', strlen($dataPayload) + 4) . $dataPayload;
      $commandPayload = "SELECT 1\0";
      $command = 'C' . pack('N', strlen($commandPayload) + 4) . $commandPayload;
      $ready = 'Z' . pack('N', 5) . 'I';
      $Description = $rowDescription(['value' => 23]);

      $SQL = 'SELECT $1::int AS value';
      $Cold = $Database->query($SQL, [42]);
      $Database->advance($Cold);
      $coldWire = (string) fread($server, 8192);

      yield assert(
         assertion: str_contains($coldWire, 'P') && str_contains($coldWire, 'D'),
         description: 'The cold batch still Parses and Describes the statement'
      );

      fwrite(
         $server,
         "{$parseComplete}{$parameterDescription}{$Description}{$bindComplete}"
         . "{$Description}{$dataRow}{$command}{$ready}"
      );
      $Database->advance($Cold);

      $Driver = $Cold->Protocol;
      $Reflection = new ReflectionProperty(PostgreSQL::class, 'layouts');
      $layouts = $Driver instanceof PostgreSQL ? $Reflection->getValue($Driver) : [];

      yield assert(
         assertion: $Cold->error === null
            && ($layouts[$Cold->statement]['columns'] ?? null) === ['value']
            && ($layouts[$Cold->statement]['types'] ?? null) === [23],
         description: 'The RowDescription is cached as the statement row layout'
      );

      // @ Warm operation — the layout is known, so the batch must not ask for it again
      $Warm = $Database->query($SQL, [43]);

      yield assert(
         assertion: $Warm->write !== '' && $Warm->write[0] === 'B' && str_contains($Warm->write, 'D') === false,
         description: 'The warm batch binds without a portal Describe once the layout is cached'
      );

      yield assert(
         assertion: $Warm->columns === ['value'] && $Warm->types === [23],
         description: 'The warm operation applies the cached columns and type OIDs at compose time'
      );

      fclose($server);
      $Database->Connection->disconnect();
   }
);
