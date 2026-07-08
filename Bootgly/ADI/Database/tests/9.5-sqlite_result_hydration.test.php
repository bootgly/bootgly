<?php

use function extension_loaded;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Databases\SQL;


return new Specification(
   description: 'SQLite: mutations report affected rows and generated row ids',
   skip: extension_loaded('sqlite3') === false,
   test: function () {
      $Database = new SQL(['driver' => 'sqlite', 'database' => ':memory:']);

      $Database->query('CREATE TABLE tasks (id INTEGER PRIMARY KEY, title TEXT, done INTEGER DEFAULT 0)');

      $First = $Database->query("INSERT INTO tasks (title) VALUES ('write driver')");

      yield assert(
         assertion: $First->Result?->inserted === 1 && $First->Result->affected === 1,
         description: 'INSERT exposes the generated row id through Result->inserted'
      );

      $Second = $Database->query("INSERT INTO tasks (title) VALUES ('write tests')");

      yield assert(
         assertion: $Second->Result?->inserted === 2,
         description: 'Result->inserted tracks the last generated row id'
      );

      // ? RETURNING is blocked — the sqlite3 extension steps the statement
      //   twice (internal step + reset before the fetch), duplicating writes.
      $Returned = $Database->query("INSERT INTO tasks (title) VALUES ('ship v0.22') RETURNING id, title");

      yield assert(
         assertion: $Returned->finished
            && $Returned->error === 'SQLite RETURNING is not supported: the sqlite3 extension executes the statement twice, duplicating the write. Read generated ids from Result->inserted.',
         description: 'INSERT ... RETURNING fails fast instead of duplicating the write'
      );

      $Literal = $Database->query("INSERT INTO tasks (title) VALUES ('RETURNING soon')");

      yield assert(
         assertion: $Literal->error === null && $Literal->Result?->inserted === 3,
         description: 'RETURNING inside string literals is not blocked'
      );

      $Update = $Database->query('UPDATE tasks SET done = 1');

      yield assert(
         assertion: $Update->Result?->affected === 3
            && $Update->Result->status === 'UPDATE 3'
            && $Update->Result->inserted === 0,
         description: 'UPDATE reports affected rows and no generated id'
      );

      $Delete = $Database->query('DELETE FROM tasks WHERE done = 1');

      yield assert(
         assertion: $Delete->Result?->affected === 3 && $Delete->Result->status === 'DELETE 3',
         description: 'DELETE reports affected rows in the status tag'
      );
   }
);
