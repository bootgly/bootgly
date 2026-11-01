<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Operation;
use Bootgly\ADI\Databases\SQL;


return new Test(
   description: 'SQLite: a refused statement or teardown never leaves its connection inside a transaction',
   skip: extension_loaded('sqlite3') === false,
   test: function () {
      $directory = sys_get_temp_dir();
      $files = [];
      /**
       * A fresh file database and a facade factory on it — one pooled
       * handle per facade, so each one is its own connection.
       *
       * @return Closure(int=):SQL
       */
      $open = static function (string $mode = 'delete') use ($directory, &$files): Closure {
         $file = "{$directory}/bootgly-sqlite-refused-" . uniqid() . '.db';
         $files[] = $file;

         $factory = static fn (int $statements = 256): SQL => new SQL([
            'driver' => 'sqlite',
            'database' => $file,
            'timeout' => 0.1,
            'statements' => $statements,
            'pool' => ['min' => 0, 'max' => 1],
         ]);

         $Setup = $factory();
         $Setup->query("PRAGMA journal_mode = {$mode}");

         return $factory;
      };
      $run = static function (SQL $Database, string $SQL, array $parameters = []): Operation {
         $Operation = $Database->query($SQL, $parameters);

         try {
            $Database->await($Operation);
         }
         catch (Throwable) {
         }

         return $Operation;
      };

      try {
         // # A cached write refused with `database is locked`
         //   SQLite parks a statement whose step was refused, still active, and
         //   an active statement keeps the autocommit transaction open: B's next
         //   write reported success inside it, invisible to every other
         //   connection, and was rolled back when the statement was re-armed,
         //   evicted or the handle closed.
         $factory = $open();
         $A = $factory();
         $B = $factory();
         $C = $factory();

         $run($A, 'CREATE TABLE orders (id INTEGER PRIMARY KEY, sku TEXT)');
         $Transaction = $A->begin();
         $A->await($Transaction->Operation);
         $Transaction->await($Transaction->query('INSERT INTO orders (sku) VALUES (?1)', ['a1']));

         $Refused = $run($B, 'INSERT INTO orders (sku) VALUES (?1)', ['b1']);

         $A->await($Transaction->commit());

         $Update = $run($B, 'UPDATE orders SET sku = ?1 WHERE id = ?2', ['a1-paid', 1]);
         $Seen = $run($C, 'SELECT sku FROM orders');
         $Write = $run($C, 'INSERT INTO orders (sku) VALUES (?1)', ['z1']);

         yield assert(
            assertion: $Refused->error === 'Unable to execute statement: database is locked'
               && $Refused->code === '5',
            description: 'The refused step keeps its message and extended code, found: '
               . json_encode([$Refused->error, $Refused->code])
         );

         yield assert(
            assertion: $Update->error === null
               && $Seen->rows === [['sku' => 'a1-paid']]
               && $Write->error === null,
            description: 'A write after the refused statement commits, and other connections see it, found: '
               . json_encode([$Seen->rows, $Write->error])
         );

         // # A transaction retried after `517`
         //   Under WAL a stale snapshot refuses the write at once with 517, and
         //   the documented recovery is to roll back and retry the whole
         //   transaction — which kept reading the stale snapshot, and failed
         //   517 on every retry, while the refused statement stayed active.
         $factory = $open('wal');
         $A = $factory();
         $B = $factory();

         $run($A, 'CREATE TABLE stock (id INTEGER PRIMARY KEY, qty INTEGER)');
         $run($A, 'INSERT INTO stock (qty) VALUES (10)');

         $attempt = static function (bool $interleave) use ($A, $B, $run): array {
            $Transaction = $B->begin();
            $B->await($Transaction->Operation);
            $Read = $Transaction->query('SELECT count(*) AS n FROM stock');
            $Transaction->await($Read);

            if ($interleave) {
               $run($A, 'INSERT INTO stock (qty) VALUES (?1)', [5]);
            }

            $Update = $Transaction->query('UPDATE stock SET qty = qty - ?1 WHERE id = ?2', [1, 1]);

            try {
               $Transaction->await($Update);
            }
            catch (Throwable) {
            }

            $Teardown = $Update->error === null ? $Transaction->commit() : $Transaction->rollback();

            try {
               $B->await($Teardown);
            }
            catch (Throwable) {
            }

            return [$Update->error, $Update->code, $Read->Result?->cell];
         };

         $first = $attempt(true);
         $retry = $attempt(false);
         $Truth = $run($A, 'SELECT group_concat(qty) AS q FROM stock');

         yield assert(
            assertion: $first[1] === '517'
               && $retry[0] === null
               && $retry[2] === 2
               && $Truth->Result?->cell === '9,5',
            description: 'A transaction retried after 517 reads a fresh snapshot and commits, found: '
               . json_encode([$first, $retry, $Truth->Result?->cell])
         );

         // # A COMMIT refused by a deferred constraint
         //   SQLite keeps the transaction open when its COMMIT fails, while the
         //   Transaction has already let go of it and the pool lends the
         //   connection on: the next write reported success inside it, the file
         //   stayed locked, and the next begin() failed.
         $factory = $open();
         $Database = $factory();
         $Other = $factory();

         $run($Database, 'CREATE TABLE parent (id INTEGER PRIMARY KEY)');
         $run($Database, 'CREATE TABLE child (id INTEGER PRIMARY KEY, parent_id INTEGER REFERENCES parent(id) DEFERRABLE INITIALLY DEFERRED)');
         $run($Database, 'CREATE TABLE ledger (id INTEGER PRIMARY KEY, note TEXT)');

         $Transaction = $Database->begin();
         $Database->await($Transaction->Operation);
         $Transaction->await($Transaction->query('INSERT INTO child (id, parent_id) VALUES (1, 999)'));
         $Commit = $Transaction->commit();

         try {
            $Database->await($Commit);
         }
         catch (Throwable) {
         }

         $After = $run($Database, "INSERT INTO ledger (note) VALUES ('after')");
         $Count = $run($Other, 'SELECT count(*) AS n FROM ledger');
         $Again = $Database->begin();

         try {
            $Database->await($Again->Operation);
         }
         catch (Throwable) {
         }

         yield assert(
            assertion: $Commit->error === 'Unable to execute statement: FOREIGN KEY constraint failed'
               && $After->error === null
               && $Count->Result?->cell === 1
               && $Again->Operation?->error === null,
            description: 'A COMMIT refused by a deferred constraint ends its transaction, found: '
               . json_encode([$Count->error ?? $Count->Result?->cell, $Again->Operation?->error])
         );

         $Database->await($Again->rollback());

         // # A COMMIT refused with `database is locked`
         //   Another connection holds a read transaction past `timeout`, so the
         //   COMMIT cannot take the write lock. SQLite keeps the transaction
         //   open for a retry nobody can make any more.
         $factory = $open();
         $Writer = $factory();
         $Reader = $factory();

         $run($Writer, 'CREATE TABLE ledger (id INTEGER PRIMARY KEY, note TEXT)');
         $Holding = $Reader->begin();
         $Reader->await($Holding->Operation);
         $Holding->await($Holding->query('SELECT count(*) AS n FROM ledger'));

         $Transaction = $Writer->begin();
         $Writer->await($Transaction->Operation);
         $Transaction->await($Transaction->query("INSERT INTO ledger (note) VALUES ('refused')"));
         $Busy = $Transaction->commit();

         try {
            $Writer->await($Busy);
         }
         catch (Throwable) {
         }

         $Reader->await($Holding->commit());

         $Later = $run($Writer, "INSERT INTO ledger (note) VALUES ('later')");
         $Notes = $run($Reader, 'SELECT group_concat(note) AS notes FROM ledger');

         yield assert(
            assertion: $Busy->error === 'Unable to execute statement: database is locked'
               && $Later->error === null
               && $Notes->Result?->cell === 'later',
            description: 'A COMMIT refused with database is locked ends its transaction, found: '
               . json_encode([$Busy->error, $Notes->error ?? $Notes->Result?->cell])
         );

         // # A cached read refused with `database is locked`
         //   A refused read left active keeps its connection's shared lock
         //   after every later statement there, so another connection's next
         //   COMMIT could never take the write lock. B's schema is loaded first:
         //   otherwise the refusal is the prepare's, not the step's.
         $factory = $open();
         $A = $factory();
         $B = $factory();
         $C = $factory();

         $run($A, 'CREATE TABLE orders (id INTEGER PRIMARY KEY, sku TEXT)');
         $run($B, 'SELECT count(*) FROM orders');
         $run($A, 'BEGIN EXCLUSIVE');
         $Read = $run($B, 'SELECT sku FROM orders WHERE id > ?1', [0]);
         $run($A, 'COMMIT');
         $run($B, 'SELECT count(*) AS n FROM orders');

         $Transaction = $A->begin();
         $A->await($Transaction->Operation);
         $Transaction->await($Transaction->query('INSERT INTO orders (sku) VALUES (?1)', ['a1']));
         $Commit = $Transaction->commit();

         try {
            $A->await($Commit);
         }
         catch (Throwable) {
         }

         $Seen = $run($C, 'SELECT group_concat(sku) AS s FROM orders');

         yield assert(
            assertion: $Read->error === 'Unable to execute statement: database is locked'
               && $Read->code === '5'
               && $Commit->error === null
               && $Seen->Result?->cell === 'a1',
            description: 'A refused read leaves no lock behind, so the next COMMIT elsewhere succeeds, found: '
               . json_encode([$Read->error, $Commit->error, $Seen->error ?? $Seen->Result?->cell])
         );

         // # A teardown refused because the engine already ended the transaction
         //   `INSERT OR ROLLBACK` rolls the transaction back itself, so the
         //   COMMIT that follows fails with no transaction to end — and so does
         //   the rollback that ends a refused teardown. That one must be
         //   swallowed: escaping, it stranded the teardown's claim and wedged
         //   the pool for good.
         $Memory = new SQL(['driver' => 'sqlite', 'database' => ':memory:', 'pool' => ['min' => 0, 'max' => 1]]);
         $run($Memory, 'CREATE TABLE t (id INTEGER PRIMARY KEY, u TEXT UNIQUE)');
         $run($Memory, "INSERT INTO t (u) VALUES ('dup')");

         $Transaction = $Memory->begin();
         $Memory->await($Transaction->Operation);
         $Conflict = $Transaction->query('INSERT OR ROLLBACK INTO t (u) VALUES (?1)', ['dup']);

         try {
            $Transaction->await($Conflict);
         }
         catch (Throwable) {
         }

         $escaped = null;

         try {
            $Ended = $Transaction->commit();

            try {
               $Memory->await($Ended);
            }
            catch (RuntimeException) {
            }
         }
         catch (Throwable $Throwable) {
            $escaped = $Throwable->getMessage();
            $Ended = null;
         }

         $idle = count($Memory->Pool->idle);
         $Restart = $Memory->begin();

         try {
            $Memory->await($Restart->Operation);
         }
         catch (Throwable) {
         }

         yield assert(
            assertion: $escaped === null
               && $Ended?->error === 'Unable to execute statement: cannot commit - no transaction is active'
               && $idle === 1
               && $Restart->Operation?->error === null,
            description: 'A teardown with no transaction left settles, and the pool serves the next begin(), found: '
               . json_encode([$escaped, $Ended?->error, $idle, $Restart->Operation?->error])
         );
      }
      finally {
         foreach ($files as $file) {
            foreach (['', '-journal', '-wal', '-shm'] as $suffix) {
               if (is_file("{$file}{$suffix}")) {
                  unlink("{$file}{$suffix}");
               }
            }
         }
      }
   }
);
