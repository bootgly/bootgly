<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Drivers\SQLite;


return new Test(
   description: 'SQLite: a failed statement never costs the next write that reuses it',
   skip: extension_loaded('sqlite3') === false,
   test: function () {
      $insert = 'INSERT INTO people (email) VALUES (?1)';
      $open = function (int $statements): SQL {
         $Database = new SQL([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'statements' => $statements,
            'pool' => [
               'min' => 0,
               'max' => 1,
            ],
         ]);
         $Database->query('CREATE TABLE people (id INTEGER PRIMARY KEY, email TEXT UNIQUE)');

         return $Database;
      };
      $emails = fn (SQL $Database): mixed =>
         $Database->query('SELECT group_concat(email) AS emails FROM people')->Result?->cell;

      // # One rejected duplicate must not take the next valid write with it
      $Database = $open(256);

      /** @var array<int,SQL\Operation> $Operations */
      $Operations = [];

      foreach (['ann', 'bob', 'ann', 'cid', 'dee', 'eve'] as $email) {
         $Operations[] = $Database->query($insert, [$email]);
      }

      yield assert(
         assertion: $emails($Database) === 'ann,bob,cid,dee,eve',
         description: 'Every unique row is written even though one duplicate was rejected'
      );

      $Duplicate = $Operations[2];
      $Next = $Operations[3];

      yield assert(
         assertion: $Duplicate->error !== null
            && str_contains($Duplicate->error, 'UNIQUE constraint failed')
            && $Next->error === null
            && $Next->Result?->affected === 1,
         description: 'The duplicate still reports the engine error and the next write succeeds'
      );

      $inherited = false;

      foreach ($Operations as $Operation) {
         if (str_contains((string) $Operation->error, 'Unable to reset statement:')) {
            $inherited = true;
         }
      }

      yield assert(
         assertion: $inherited === false,
         description: 'No operation ever inherits the previous execution error through the re-arm'
      );

      // @ The compiled statement is kept: a rejected row is a runtime error,
      //   not a reason to re-parse the SQL — the sibling PostgreSQL driver
      //   evicts only for errors that invalidate the statement itself.
      $Driver = $Next->Protocol;

      yield assert(
         assertion: $Driver instanceof SQLite && array_keys($Driver->statements) === [$insert],
         description: 'The rejected execution leaves the prepared statement in the cache'
      );

      // # Inside a transaction the loss is invisible at the boundary
      $Database = $open(256);
      $Transaction = $Database->begin();
      $Database->await($Transaction->Operation);

      $Transaction->query($insert, ['ann']);
      $Transaction->query($insert, ['ann']);
      $Kept = $Transaction->query($insert, ['cid']);
      $Commit = $Transaction->commit();

      yield assert(
         assertion: $Kept->error === null
            && $Commit->error === null
            && $emails($Database) === 'ann,cid',
         description: 'A duplicate inside a transaction does not silently drop the row after it'
      );

      // # Control — with the cache off the sequence was always intact
      $Control = $open(0);

      foreach (['ann', 'bob', 'ann', 'cid', 'dee', 'eve'] as $email) {
         $Control->query($insert, [$email]);
      }

      $Uncached = $Control->query($insert, ['fay'])->Protocol;

      yield assert(
         assertion: $emails($Control) === 'ann,bob,cid,dee,eve,fay'
            && $Uncached instanceof SQLite
            && $Uncached->statements === [],
         description: 'The uncached path lands every unique row and caches nothing'
      );
   }
);
