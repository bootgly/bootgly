<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;


return new Test(
   description: 'Pool: a saturated wait() refuses the operation instead of parking it for replay',
   skip: extension_loaded('sqlite3') === false,
   test: function () {
      $directory = sys_get_temp_dir();
      $file = $directory . '/bootgly-pool5-' . uniqid() . '.db';

      /**
       * Opens a pool over a FRESH file database. The file matters: `:memory:`
       * gives every pooled connection its own disjoint database (LITE-1), which
       * would hide the replay this case exists to catch.
       */
      $open = static function (int $max) use ($file): SQL {
         @unlink($file);

         $Database = new SQL([
            'driver'   => 'sqlite',
            'database' => $file,
            'timeout'  => 3.0,
            'pool'     => ['min' => 0, 'max' => $max],
         ]);
         $Database->await($Database->query('CREATE TABLE t (v TEXT)'));

         return $Database;
      };

      /**
       * Reads the table back through a connection the pool never owned, so the
       * assertion measures committed data rather than framework bookkeeping.
       *
       * @return array<int,string>
       */
      $rows = static function () use ($file): array {
         $Handle = new SQLite3($file);
         $Result = $Handle->query('SELECT v FROM t ORDER BY v');
         $read = [];

         while ($row = $Result->fetchArray(SQLITE3_ASSOC)) {
            $read[] = $row['v'];
         }

         $Handle->close();

         return $read;
      };

      try {
         // @@ A transaction locks the only connection, so the next query has no
         //    capacity and assign() parks it — the shape wait() used to report
         //    as a hard failure while keeping a strong reference to it.
         $Database = $open(1);
         $Pool = $Database->Pool;

         $Transaction = $Database->begin();
         $Abandoned = $Database->query("INSERT INTO t VALUES ('paid')");

         $error = null;

         try {
            $Database->await($Abandoned);
         }
         catch (Throwable $Throwable) {
            $error = $Throwable->getMessage();
         }

         yield assert(
            assertion: $error === 'Database pool has no capacity for the operation.',
            description: 'A saturated await() names the real cause, got: ' . var_export($error, true)
         );

         yield assert(
            assertion: $Pool->pending === [],
            description: 'The refused operation leaves the pending queue, so promote() '
               . 'can never put it on the wire later; ' . count($Pool->pending) . ' still queued'
         );

         // @ The caller was told the write failed: it compensates and leaves.
         try {
            $Database->await($Transaction->rollback());
         }
         catch (Throwable) {
         }

         $committed = $rows();

         yield assert(
            assertion: $committed === [],
            description: 'A write the caller was told had failed must never land after its '
               . 'compensating rollback, found: ' . json_encode($committed)
         );

         // @@ The retry reflex: the caller retries the write it was told failed.
         //    Both landing is one insert committed twice.
         $Database = $open(1);
         $Transaction = $Database->begin();

         try {
            $Database->await($Database->query("INSERT INTO t VALUES ('once')"));
         }
         catch (Throwable) {
         }

         try {
            $Database->await($Transaction->rollback());
         }
         catch (Throwable) {
         }

         try {
            $Database->await($Database->query("INSERT INTO t VALUES ('once')"));
         }
         catch (Throwable) {
         }

         $committed = $rows();

         yield assert(
            assertion: $committed === ['once'],
            description: 'Retrying a refused write commits it exactly once, found: '
               . json_encode($committed)
         );

         // @@ Control — an unsaturated pool is untouched by any of this
         $Database = $open(4);
         $Plain = $Database->query("INSERT INTO t VALUES ('plain')");
         $error = null;

         try {
            $Database->await($Plain);
         }
         catch (Throwable $Throwable) {
            $error = $Throwable->getMessage();
         }

         yield assert(
            assertion: $error === null && $Plain->finished && $rows() === ['plain']
               && $Database->Pool->pending === [],
            description: 'A pool with capacity still runs the write normally'
         );

         // @@ Control — a transaction that commits still commits its own work,
         //    so the refusal above is about capacity and not about locking
         $Database = $open(1);
         $Transaction = $Database->begin();
         $Database->await($Transaction->query("INSERT INTO t VALUES ('inside-tx')"));
         $Database->await($Transaction->commit());

         $committed = $rows();

         yield assert(
            assertion: $committed === ['inside-tx'],
            description: 'A committed transaction still commits its own work, found: '
               . json_encode($committed)
         );
      }
      finally {
         @unlink($file);
      }
   }
);
