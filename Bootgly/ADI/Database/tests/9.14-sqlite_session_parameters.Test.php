<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;


return new Test(
   description: 'SQLite: startup session parameters are refused at config time, never run without them',
   skip: extension_loaded('sqlite3') === false,
   test: function () {
      // # Refused — the config itself fails loud, so no handle is ever opened
      $refusal = '';

      try {
         new SQL([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'parameters' => ['search_path' => 'app'],
         ]);
      }
      catch (InvalidArgumentException $Exception) {
         $refusal = $Exception->getMessage();
      }

      yield assert(
         assertion: $refusal === 'Database `parameters` are PostgreSQL only: the sqlite driver has no startup session parameters, so set the session after connecting instead.',
         description: "A non-empty `parameters` is refused at config time — {$refusal}"
      );

      // # Control — without parameters the same query runs
      $Control = new SQL([
         'driver' => 'sqlite',
         'database' => ':memory:',
         'parameters' => [],
      ]);
      $Operation = $Control->query('SELECT 1 AS one');

      yield assert(
         assertion: $Operation->error === null && ($Operation->Result?->rows ?? []) === [['one' => 1]],
         description: 'An empty `parameters` map opens and runs as before'
      );
   }
);
