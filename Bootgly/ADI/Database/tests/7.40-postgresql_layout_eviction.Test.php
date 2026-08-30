<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Drivers\PostgreSQL;


return new Test(
   description: 'Database: PostgreSQL never caches a row layout for an evicted statement',
   test: function () {
      [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($server, false);

      // ! Budget of one — the second statement evicts the first
      $Database = new SQL(['driver' => 'pgsql', 'statements' => 1, 'pool' => ['min' => 0, 'max' => 1]]);
      $Database->Connection->attach($client);

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

      $First = $Database->query('SELECT $1::int AS alpha', [1]);
      $Database->advance($First);
      fread($server, 8192);

      // @ Answer split before the RowDescription — the registration is cached,
      //   its layout is not yet known
      fwrite($server, "{$parseComplete}{$parameterDescription}");
      $Database->advance($First);

      // @ A sibling statement trips the one-entry budget and evicts the first
      $Second = $Database->query('SELECT $1::int AS beta', [2]);
      $Driver = $First->Protocol;
      $Reflection = new ReflectionProperty(PostgreSQL::class, 'layouts');
      $statements = $Driver instanceof PostgreSQL ? $Driver->statements : [];

      yield assert(
         assertion: isset($statements[$First->statement]) === false,
         description: 'The budget evicts the first statement when the sibling is composed'
      );

      // @ The evicted statement's RowDescription arrives late
      fwrite($server, $rowDescription(['alpha' => 23]));
      $Database->advance($First);
      $layouts = $Driver instanceof PostgreSQL ? $Reflection->getValue($Driver) : [];

      // ? A layout without its cache entry would be applied to the NEXT
      //   registration of the same content-derived name — the backend cannot
      //   catch that, because its plan is new
      yield assert(
         assertion: isset($layouts[$First->statement]) === false,
         description: 'A RowDescription for an evicted statement leaves no orphan layout behind'
      );

      fclose($server);
      $Database->Connection->disconnect();
   }
);
