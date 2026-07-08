<?php

use function extension_loaded;
use function spl_object_id;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Databases\SQL;


return new Specification(
   description: 'SQLite: transactions pin one pooled connection through savepoints',
   skip: extension_loaded('sqlite3') === false,
   test: function () {
      $Database = new SQL(['driver' => 'sqlite', 'database' => ':memory:']);

      $Database->query('CREATE TABLE ledger (id INTEGER PRIMARY KEY, amount INTEGER)');

      // # Rollback
      $Transaction = $Database->begin();
      $Insert = $Transaction->query('INSERT INTO ledger (amount) VALUES (?1)', [100]);

      yield assert(
         assertion: $Insert->error === null && $Insert->Result?->affected === 1,
         description: 'Transaction queries run on the pinned connection'
      );

      $Transaction->rollback();
      $Count = $Database->query('SELECT count(*) AS total FROM ledger');

      yield assert(
         assertion: $Count->Result?->cell === 0,
         description: 'ROLLBACK discards the transaction writes'
      );

      // # Savepoints + commit
      $Transaction = $Database->begin();
      $Outer = $Transaction->query('INSERT INTO ledger (amount) VALUES (?1)', [200]);

      $Transaction->begin();
      $Inner = $Transaction->query('INSERT INTO ledger (amount) VALUES (?1)', [300]);
      $Transaction->rollback();

      yield assert(
         assertion: $Outer->error === null && $Inner->error === null
            && $Outer->Connection !== null && $Inner->Connection !== null
            && spl_object_id($Outer->Connection) === spl_object_id($Inner->Connection),
         description: 'Nested savepoints share the pinned connection'
      );

      $Transaction->commit();
      $Final = $Database->query('SELECT amount FROM ledger ORDER BY id');

      yield assert(
         assertion: $Final->Result?->rows === [['amount' => 200]],
         description: 'COMMIT keeps the outer write and discards the rolled-back savepoint'
      );

      $After = $Database->query('INSERT INTO ledger (amount) VALUES (400)');

      yield assert(
         assertion: $After->error === null,
         description: 'The connection returns to the pool after COMMIT'
      );
   }
);
