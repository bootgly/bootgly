<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Operation\OperationStates;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Operation as SQLOperation;


return new Test(
   description: 'Database: pool never re-prepares a finished operation',
   skip: extension_loaded('sqlite3') === false,
   test: function () {
      $Database = new SQL([
         'driver' => 'sqlite',
         'database' => ':memory:',
         'pool' => [
            'min' => 0,
            'max' => 1,
         ],
      ]);

      // ! Synchronous driver — every query resolves inside prepare().
      $Database->query('CREATE TABLE t (v TEXT)');
      $reseed = static function () use ($Database): void {
         $Database->query('DELETE FROM t');
         $Database->query("INSERT INTO t (v) VALUES ('keep-1'), ('keep-2'), ('keep-3')");
      };
      $count = static function () use ($Database): mixed {
         return $Database->query('SELECT count(*) AS total FROM t')->Result?->cell;
      };

      $reseed();

      yield assert(
         assertion: $count() === 3,
         description: 'Seed leaves three rows on the table'
      );

      // # Path A — a Transaction-refused operation must not execute through await()
      $Transaction = $Database->begin();
      $Transaction->commit();
      $Refused = $Transaction->query('DELETE FROM t');
      $caught = null;

      try {
         $Database->await($Refused);
      }
      catch (RuntimeException $Exception) {
         $caught = $Exception->getMessage();
      }

      yield assert(
         assertion: $caught === 'SQL transaction is not active.'
            && $count() === 3
            && $Refused->Result === null,
         description: 'A refused transaction statement throws without touching the table'
      );

      $reseed();

      // # Path B — a cancelled pending operation must not execute through promote()
      $Held = $Database->begin();
      $Cancelled = $Database->query('DELETE FROM t');

      yield assert(
         assertion: $Cancelled->state === OperationStates::Pending
            && $Cancelled->Protocol === null
            && count($Database->Pool->pending) === 1,
         description: 'With the single connection held, the delete parks as pending'
      );

      $Database->cancel($Cancelled);

      yield assert(
         assertion: $Cancelled->finished
            && $Cancelled->error === 'Database operation has no protocol to cancel.',
         description: 'Cancelling a parked operation fails it while still queued'
      );

      $Commit = $Held->commit();
      $Database->await($Commit);

      yield assert(
         assertion: $Commit->error === null
            && $count() === 3
            && $Cancelled->Result === null
            && $Database->Pool->pending === []
            && $Database->Pool->created === 1,
         description: 'The commit-time promote() drains the cancelled delete without running it'
      );

      $reseed();

      // # Unit surface — assign() must refuse any finished operation
      $Foreign = new SQLOperation(null, 'DELETE FROM t');
      $Foreign->fail('refused');
      $Returned = $Database->Pool->assign($Foreign);

      yield assert(
         assertion: $Returned === $Foreign
            && $Foreign->Protocol === null
            && $Foreign->Result === null
            && $count() === 3,
         description: 'assign() returns a finished operation untouched'
      );
   }
);
