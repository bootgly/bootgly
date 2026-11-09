<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Drivers\PostgreSQL\Encoder;


return new Test(
   description: 'Database: PostgreSQL infers parameter OIDs from the actual values',
   test: function () {
      $Database = new SQL;
      $Encoder = new Encoder;
      $parse = fn (string $statement, string $SQL, array $types): string => $Encoder->encode(Encoder::PARSE, [
         'statement' => $statement,
         'sql' => $SQL,
         'types' => $types,
      ]);

      $SQL = 'SELECT $1 AS moment';
      $Operation = $Database->query($SQL, [1893456000000]);
      $expected = $parse($Operation->statement, $SQL, [20]);

      yield assert(
         assertion: substr($Operation->write, 0, strlen($expected)) === $expected,
         description: 'An int beyond int4 range declares int8 so the backend never truncates it'
      );

      $SQL = 'SELECT $1 AS label';
      $Operation = $Database->query($SQL, ['x']);
      $expected = $parse($Operation->statement, $SQL, [0]);

      yield assert(
         assertion: substr($Operation->write, 0, strlen($expected)) === $expected,
         description: 'A string without an explicit cast leaves the OID for the backend to infer'
      );

      $SQL = 'SELECT substr($1, $2, $3) AS v';
      $Operation = $Database->query($SQL, ['abc', 1, 2]);
      $expected = $parse($Operation->statement, $SQL, [0, 23, 23]);

      yield assert(
         assertion: substr($Operation->write, 0, strlen($expected)) === $expected,
         description: 'In-range ints keep int4 while sibling strings stay backend-inferred'
      );

      $SQL = 'SELECT $1 AS small';
      $Operation = $Database->query($SQL, [5]);
      $expected = $parse($Operation->statement, $SQL, [23]);

      yield assert(
         assertion: substr($Operation->write, 0, strlen($expected)) === $expected,
         description: 'Small ints keep declaring int4'
      );

      // # Cast-like text inside literals and comments is not a cast
      $SQL = "UPDATE templates SET body = 'uses \$1::int4 syntax' WHERE slug = \$1";
      $Operation = $Database->query($SQL, ['home']);
      $expected = $parse($Operation->statement, $SQL, [0]);

      yield assert(
         assertion: substr($Operation->write, 0, strlen($expected)) === $expected,
         description: 'A cast written inside a string literal never types a string parameter'
      );

      $SQL = 'SELECT body FROM templates WHERE slug = $1 -- TODO migrate to $1::int4';
      $Operation = $Database->query($SQL, ['home']);
      $expected = $parse($Operation->statement, $SQL, [0]);

      yield assert(
         assertion: substr($Operation->write, 0, strlen($expected)) === $expected,
         description: 'A cast written inside a comment never types a string parameter'
      );
   }
);
