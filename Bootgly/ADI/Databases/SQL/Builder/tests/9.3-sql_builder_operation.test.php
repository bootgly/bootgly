<?php

namespace Bootgly\ADI\Databases\SQL\Builder\Tests\Operation;


use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;
use function assert;
use function fclose;
use function fread;
use function stream_set_blocking;
use function stream_socket_pair;
use function str_contains;
use InvalidArgumentException;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Operators;
use Bootgly\ADI\Databases\SQL\Normalized;


enum Tables: string
{
   case Users = 'users';
}

enum Columns: string
{
   case Id = 'id';
}


return new Specification(
   description: 'Database: SQL builder integrates with SQL query operations',
   test: function () {
      [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($server, false);

      $Database = new SQL;
      $Database->Connection->attach($client);
      $Builder = $Database
         ->table(Tables::Users)
         ->select(Columns::Id)
         ->filter(Columns::Id, Operators::Equal, 42);
      $Normalized = new Normalized($Builder);

      yield assert(
         assertion: $Normalized->sql === 'SELECT "id" FROM "users" WHERE "id" = $1'
            && $Normalized->parameters === [42],
         description: 'Normalized normalizes Builder instances into SQL and parameters'
      );

      $Normalized = new Normalized($Builder->compile());

      yield assert(
         assertion: $Normalized->sql === 'SELECT "id" FROM "users" WHERE "id" = $1'
            && $Normalized->parameters === [42],
         description: 'Normalized normalizes compiled Query instances into SQL and parameters'
      );

      $First = $Builder->compile();
      $Second = $Builder->compile();

      yield assert(
         assertion: $First === $Second,
         description: 'Builder memoizes repeated compile() calls until the next fluent mutation'
      );

      $blocked = false;

      try {
         new Normalized('   ');
      }
      catch (InvalidArgumentException) {
         $blocked = true;
      }

      yield assert(
         assertion: $blocked,
         description: 'Normalized rejects empty SQL text'
      );

      $Operation = $Database->query($Builder);

      yield assert(
         assertion: $Operation->sql === 'SELECT "id" FROM "users" WHERE "id" = $1'
            && $Operation->parameters === [42],
         description: 'SQL::query accepts Builder and creates an operation from compiled SQL'
      );

      $Database->advance($Operation);
      $wire = fread($server, 8192);

      yield assert(
         assertion: str_contains($wire, $Operation->sql),
         description: 'Compiled builder SQL reaches the PostgreSQL wire path'
      );

      fclose($server);
      $Database->Connection->disconnect();
   }
);
