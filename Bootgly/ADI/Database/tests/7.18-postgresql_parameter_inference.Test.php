<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Drivers\PostgreSQL\Encoder;


return new Test(
   description: 'Database: PostgreSQL infers parameter OIDs from the actual values',
   test: function () {
      $Database = new SQL;
      $Encoder = new Encoder;
      $parse = fn (string $statement, string $sql, array $types): string => $Encoder->encode(Encoder::PARSE, [
         'statement' => $statement,
         'sql' => $sql,
         'types' => $types,
      ]);

      $sql = 'SELECT $1 AS moment';
      $Operation = $Database->query($sql, [1893456000000]);
      $expected = $parse($Operation->statement, $sql, [20]);

      yield assert(
         assertion: substr($Operation->write, 0, strlen($expected)) === $expected,
         description: 'An int beyond int4 range declares int8 so the backend never truncates it'
      );

      $sql = 'SELECT $1 AS label';
      $Operation = $Database->query($sql, ['x']);
      $expected = $parse($Operation->statement, $sql, [0]);

      yield assert(
         assertion: substr($Operation->write, 0, strlen($expected)) === $expected,
         description: 'A string without an explicit cast leaves the OID for the backend to infer'
      );

      $sql = 'SELECT substr($1, $2, $3) AS v';
      $Operation = $Database->query($sql, ['abc', 1, 2]);
      $expected = $parse($Operation->statement, $sql, [0, 23, 23]);

      yield assert(
         assertion: substr($Operation->write, 0, strlen($expected)) === $expected,
         description: 'In-range ints keep int4 while sibling strings stay backend-inferred'
      );

      $sql = 'SELECT $1 AS small';
      $Operation = $Database->query($sql, [5]);
      $expected = $parse($Operation->statement, $sql, [23]);

      yield assert(
         assertion: substr($Operation->write, 0, strlen($expected)) === $expected,
         description: 'Small ints keep declaring int4'
      );
   }
);
