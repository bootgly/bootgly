<?php

namespace Bootgly\ADI\Databases\SQL\Builder\Tests\Transaction;


use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;
use function assert;
use function fclose;
use function fread;
use function fwrite;
use function pack;
use function str_contains;
use function stream_set_blocking;
use function stream_socket_pair;
use function strlen;
use ReflectionMethod;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Operators;


enum Tables: string
{
   case Users = 'users';
}

enum Columns: string
{
   case Id = 'id';
}


return new Specification(
   description: 'Database: SQL builder integrates with transaction queries',
   test: function () {
      [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($server, false);

      $complete = static function (string $command): string {
         $command = "{$command}\0";
         $commandLength = pack('N', strlen($command) + 4);
         $readyLength = pack('N', 5);

         return "C{$commandLength}{$command}Z{$readyLength}I";
      };

      $Database = new SQL([
         'pool' => [
            'min' => 0,
            'max' => 1,
         ],
      ]);
      $Database->Connection->attach($client);
      $Transaction = $Database->begin();
      $Begin = $Transaction->Operation;

      $Database->advance($Begin);
      fread($server, 8192);
      fwrite($server, $complete('BEGIN'));
      $Database->advance($Begin);

      $Builder = $Transaction
         ->table(Tables::Users)
         ->select(Columns::Id)
         ->filter(Columns::Id, Operators::Equal, 42);
      $Operation = $Transaction->query($Builder);

      yield assert(
         assertion: $Operation->Connection === $Begin->Connection
            && $Operation->sql === 'SELECT "id" FROM "users" WHERE "id" = $1'
            && $Operation->parameters === [42],
         description: 'Transaction::query accepts Builder and keeps the transaction connection pinned'
      );

      $Database->advance($Operation);
      $wire = fread($server, 8192);

      yield assert(
         assertion: str_contains($wire, $Operation->sql),
         description: 'Compiled transaction builder SQL reaches the PostgreSQL wire path'
      );

      $MySQL = new SQL([
         'driver' => 'mysql',
         'pool' => [
            'min' => 0,
            'max' => 0,
         ],
      ]);
      $Query = $MySQL
         ->table(Tables::Users)
         ->select(Columns::Id)
         ->filter(Columns::Id, Operators::Equal, 42)
         ->compile();

      yield assert(
         assertion: $Query->sql === 'SELECT `id` FROM `users` WHERE `id` = ?'
            && $Query->parameters === [42],
         description: 'SQL::table selects the builder dialect from the configured SQL driver'
      );

      $Transaction = $MySQL->begin();
      $Query = $Transaction
         ->table(Tables::Users)
         ->select(Columns::Id)
         ->filter(Columns::Id, Operators::Equal, 42)
         ->compile();

      yield assert(
         assertion: $Query->sql === 'SELECT `id` FROM `users` WHERE `id` = ?'
            && $Query->parameters === [42],
         description: 'Transaction::table selects the builder dialect from the configured SQL driver'
      );

      $Method = new ReflectionMethod($Transaction, 'quote');
      $savepoint = $Method->invoke($Transaction, 'bootgly_0');

      yield assert(
         assertion: $savepoint === '`bootgly_0`',
         description: 'Transaction savepoint identifiers use the configured SQL dialect quote rules'
      );

      fclose($server);
      $Database->Connection->disconnect();
   }
);
