<?php

use function extension_loaded;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Databases\SQL;


return new Specification(
   description: 'SQLite: simple queries hydrate rows, columns, affected and status',
   skip: extension_loaded('sqlite3') === false,
   test: function () {
      $Database = new SQL(['driver' => 'sqlite', 'database' => ':memory:']);

      $Create = $Database->query('CREATE TABLE fruits (id INTEGER PRIMARY KEY, name TEXT, weight REAL)');

      yield assert(
         assertion: $Create->Result?->status === 'CREATE' && $Create->Result->empty,
         description: 'DDL resolves with the command keyword and an empty result'
      );

      $Insert = $Database->query("INSERT INTO fruits (name, weight) VALUES ('apple', 0.2), ('grape', 0.005)");

      yield assert(
         assertion: $Insert->Result?->status === 'INSERT 0 2' && $Insert->Result->affected === 2,
         description: 'INSERT reports the affected row count in the status tag'
      );

      $Select = $Database->query('SELECT id, name FROM fruits ORDER BY id');
      $Result = $Select->Result;

      yield assert(
         assertion: $Result?->status === 'SELECT 2' && $Result->count === 2,
         description: 'SELECT reports the row count in the status tag'
      );

      yield assert(
         assertion: $Result?->columns === ['id', 'name'],
         description: 'Columns follow the SELECT projection order'
      );

      yield assert(
         assertion: $Result?->rows === [
            ['id' => 1, 'name' => 'apple'],
            ['id' => 2, 'name' => 'grape'],
         ],
         description: 'Rows hydrate as column-keyed associative arrays'
      );

      yield assert(
         assertion: $Result?->row === ['id' => 1, 'name' => 'apple'] && $Result->cell === 1,
         description: 'Result views expose the first row and first cell'
      );

      $Empty = $Database->query('SELECT id FROM fruits WHERE id = 99');

      yield assert(
         assertion: $Empty->Result?->empty === true && $Empty->Result->cell === null,
         description: 'Empty selections expose empty views'
      );
   }
);
