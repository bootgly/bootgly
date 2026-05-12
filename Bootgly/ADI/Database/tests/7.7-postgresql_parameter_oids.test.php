<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Drivers\PostgreSQL\Encoder;


return new Specification(
   description: 'Database: PostgreSQL infers parameter OIDs for NULL casts',
   test: function () {
      $sql = 'SELECT $1::int AS value, $2::bool AS flag, $3::double precision AS ratio';
      $Database = new SQL;
      $Operation = $Database->query($sql, [null, null, null]);
      $Encoder = new Encoder;
      $parse = $Encoder->encode(Encoder::PARSE, [
         'statement' => $Operation->statement,
         'sql' => $sql,
         'types' => [23, 16, 701],
      ]);

      yield assert(
         assertion: substr($Operation->write, 0, strlen($parse)) === $parse,
         description: 'Parse message contains OIDs inferred from explicit SQL casts'
      );
   }
);
