<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Operation;
use Bootgly\ADI\Database\Operation\OperationStates;


return new Specification(
   description: 'Database: query creates pending async operation',
   test: function () {
      $Database = new SQL([
         'database' => 'bootgly_test',
         'pool' => [
            'min' => 1,
            'max' => 4,
         ],
      ]);

      $Operation = $Database->query('SELECT $1::int AS value', [1]);

      yield assert(
         assertion: $Operation instanceof Operation,
         description: 'Query returns an Operation'
      );

      yield assert(
         assertion: $Operation->Connection === $Database->Connection,
         description: 'Operation references Database Connection'
      );

      yield assert(
         assertion: $Operation->sql === 'SELECT $1::int AS value',
         description: 'Operation stores SQL'
      );

      yield assert(
         assertion: $Operation->parameters === [1],
         description: 'Operation stores parameters'
      );

      yield assert(
         assertion: $Operation->state === OperationStates::Queued,
         description: 'Operation is queued by active protocol'
      );

      yield assert(
         assertion: $Operation->write !== '',
         description: 'Operation stores protocol write buffer'
      );

      yield assert(
         assertion: $Operation->finished === false,
         description: 'Operation starts pending'
      );

      yield assert(
         assertion: $Operation->Result === null,
         description: 'Operation starts without Result'
      );
   }
);
