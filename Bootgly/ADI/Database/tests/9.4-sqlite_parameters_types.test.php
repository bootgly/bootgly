<?php

use function extension_loaded;
use DateTimeImmutable;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Databases\SQL;


return new Specification(
   description: 'SQLite: positional and named parameters bind with native types',
   skip: extension_loaded('sqlite3') === false,
   test: function () {
      $Database = new SQL(['driver' => 'sqlite', 'database' => ':memory:']);

      $Database->query(<<<SQL
      CREATE TABLE samples (
         id INTEGER PRIMARY KEY,
         count INTEGER,
         ratio REAL,
         label TEXT,
         note TEXT,
         active INTEGER,
         created_at TEXT
      )
      SQL);

      $Moment = new DateTimeImmutable('2026-07-07 12:30:45.123456');
      $Insert = $Database->query(
         'INSERT INTO samples (count, ratio, label, note, active, created_at) VALUES (?1, ?2, ?3, ?4, ?5, ?6)',
         [42, 3.14, 'bootgly', null, true, $Moment]
      );

      yield assert(
         assertion: $Insert->error === null && $Insert->Result?->affected === 1,
         description: 'Positional `?N` parameters bind by 1-based position'
      );

      $Row = $Database->query('SELECT count, ratio, label, note, active, created_at FROM samples')->Result?->row;

      yield assert(
         assertion: $Row === [
            'count' => 42,
            'ratio' => 3.14,
            'label' => 'bootgly',
            'note' => null,
            'active' => 1,
            'created_at' => '2026-07-07 12:30:45.123456',
         ],
         description: 'Values round-trip with native SQLite typing (booleans as 0/1)'
      );

      $Named = $Database->query(
         'SELECT count FROM samples WHERE label = :label',
         ['label' => 'bootgly']
      );

      yield assert(
         assertion: $Named->Result?->cell === 42,
         description: 'Named parameters bind with or without the `:` prefix'
      );

      $Prefixed = $Database->query(
         'SELECT count FROM samples WHERE label = :label',
         [':label' => 'bootgly']
      );

      yield assert(
         assertion: $Prefixed->Result?->cell === 42,
         description: 'Already-prefixed named parameters bind unchanged'
      );
   }
);
