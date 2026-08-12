<?php


use ReflectionProperty;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Databases\SQL\Config;
use Bootgly\ADI\Databases\SQL\Drivers\PostgreSQL;


return new Test(
   description: 'PostgreSQL: a sibling created inside the Parse window never parses the statement again',
   test: function () {
      $connect = function (): array {
         [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         stream_set_blocking($client, false);
         stream_set_blocking($server, false);

         $Config = new Config(['driver' => 'pgsql', 'statements' => 256]);
         $Connection = new Connection($Config);
         $Connection->attach($client);

         return [new PostgreSQL($Config, $Connection), $Connection, $server];
      };

      // ! Split a composed batch into its frontend message types. Every
      //   message is <type><int32 length><payload>, so this never mistakes
      //   parameter data for a message tag.
      $messages = function (string $batch): string {
         $types = '';
         $offset = 0;

         while ($offset + 5 <= strlen($batch)) {
            $types .= $batch[$offset];
            $header = unpack('N', substr($batch, $offset + 1, 4));
            $offset += 1 + ($header[1] ?? 4);
         }

         return $types;
      };

      $parseComplete = '1' . pack('N', 4);
      $parameterPayload = pack('n', 1) . pack('N', 23);
      $parameterDescription = 't' . pack('N', strlen($parameterPayload) + 4) . $parameterPayload;
      $bindComplete = '2' . pack('N', 4);
      $commandPayload = "SELECT 1\0";
      $command = 'C' . pack('N', strlen($commandPayload) + 4) . $commandPayload;
      $ready = 'Z' . pack('N', 5) . 'I';

      $SQL = 'SELECT $1::int AS v';


      // # The window: two operations composed before either reaches the wire
      [$PostgreSQL, $Connection, $server] = $connect();

      $Owner = $PostgreSQL->query($SQL, [1]);
      $Sibling = $PostgreSQL->query($SQL, [2]);

      yield assert(
         assertion: $messages($Owner->write) === 'PDBDES',
         description: 'The first operation composes the Parse for the statement'
      );

      yield assert(
         assertion: $messages($Sibling->write) === 'BDES',
         description: 'A sibling created inside the Parse window composes no Parse of its own'
      );

      yield assert(
         assertion: $Sibling->prepared === true
            && $Sibling->statement === $Owner->statement,
         description: 'The sibling binds the very statement its owner is parsing'
      );

      // @ Drive both batches through the wire in composition order.
      $PostgreSQL->advance($Owner);
      fwrite($server, "{$parseComplete}{$parameterDescription}{$bindComplete}{$command}{$ready}");
      $PostgreSQL->advance($Owner);

      $PostgreSQL->advance($Sibling);
      fwrite($server, "{$bindComplete}{$command}{$ready}");
      $PostgreSQL->advance($Sibling);

      $wire = (string) fread($server, 65536);

      yield assert(
         assertion: substr_count($messages($wire), 'P') === 1,
         description: 'Only one Parse reaches the backend for both operations'
      );

      yield assert(
         assertion: $Owner->finished && $Owner->error === null
            && $Sibling->finished && $Sibling->error === null,
         description: 'Both operations resolve without a duplicate statement error'
      );

      $Ledger = new ReflectionProperty(PostgreSQL::class, 'preparing');

      yield assert(
         assertion: $Ledger->getValue($PostgreSQL) === [],
         description: 'The preparing ledger still drains once the statement is cached'
      );

      fclose($server);
      $Connection->disconnect();


      // # An owner abandoned before its batch reaches the wire releases the name
      [$PostgreSQL, $Connection, $server] = $connect();

      $Owner = $PostgreSQL->query($SQL, [1]);
      $PostgreSQL->abandon($Owner);

      yield assert(
         assertion: $Ledger->getValue($PostgreSQL) === [],
         description: 'An owner whose Parse never flushed does not keep the name marked'
      );

      $Next = $PostgreSQL->query($SQL, [2]);

      yield assert(
         assertion: $messages($Next->write) === 'PDBDES',
         description: 'The next operation parses the statement the abandoned owner never sent'
      );

      fclose($server);
      $Connection->disconnect();


      // # Control: a cached statement still binds without re-parsing
      [$PostgreSQL, $Connection, $server] = $connect();

      $First = $PostgreSQL->query($SQL, [1]);
      $PostgreSQL->advance($First);
      fwrite($server, "{$parseComplete}{$parameterDescription}{$bindComplete}{$command}{$ready}");
      $PostgreSQL->advance($First);
      fread($server, 65536);

      $Warm = $PostgreSQL->query($SQL, [2]);

      yield assert(
         assertion: $messages($Warm->write) === 'BDES'
            && $PostgreSQL->statements !== [],
         description: 'A cache hit still binds the statement without parsing it again'
      );

      fclose($server);
      $Connection->disconnect();
   }
);
