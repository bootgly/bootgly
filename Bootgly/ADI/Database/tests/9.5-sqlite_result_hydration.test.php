<?php

use function extension_loaded;
use SQLite3;

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

      // ? RETURNING requires libsqlite ≥ 3.35
      $returning = SQLite3::version()['versionNumber'] >= 3035000;

      if ($returning) {
         $Returned = $Database->query("INSERT INTO tasks (title) VALUES ('ship v0.22') RETURNING id, title");

         yield assert(
            assertion: $Returned->Result?->rows === [['id' => 3, 'title' => 'ship v0.22']]
               && $Returned->Result->inserted === 3,
            description: 'INSERT ... RETURNING hydrates rows and the generated id'
         );
      }

      $Update = $Database->query('UPDATE tasks SET done = 1');
      $expected = $returning ? 3 : 2;

      yield assert(
         assertion: $Update->Result?->affected === $expected
            && $Update->Result->status === "UPDATE {$expected}"
            && $Update->Result->inserted === 0,
         description: 'UPDATE reports affected rows and no generated id'
      );

      $Delete = $Database->query('DELETE FROM tasks WHERE done = 1');

      yield assert(
         assertion: $Delete->Result?->affected === $expected && $Delete->Result->status === "DELETE {$expected}",
         description: 'DELETE reports affected rows in the status tag'
      );
   }
);
