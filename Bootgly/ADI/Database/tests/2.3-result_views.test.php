<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Database\Operation\Result;


return new Specification(
   description: 'Database: operation result exposes convenience views',
   test: function () {
      $Result = new Result(
         status: 'SELECT 2',
         rows: [
            ['value' => 42, 'label' => 'bootgly'],
            ['value' => 7, 'label' => 'framework'],
         ],
         columns: ['value', 'label'],
         affected: 2
      );

      yield assert(
         assertion: $Result->row === ['value' => 42, 'label' => 'bootgly'],
         description: 'Result row view exposes the first decoded row'
      );

      yield assert(
         assertion: $Result->cell === 42,
         description: 'Result cell view exposes the first cell from the first row'
      );

      yield assert(
         assertion: $Result->count === 2,
         description: 'Result count view exposes the selected row count'
      );

      yield assert(
         assertion: $Result->empty === false,
         description: 'Result empty view reports rows are available'
      );

      $Empty = new Result;

      yield assert(
         assertion: $Empty->row === [],
         description: 'Empty result row view returns an empty row'
      );

      yield assert(
         assertion: $Empty->cell === null,
         description: 'Empty result cell view returns null'
      );

      yield assert(
         assertion: $Empty->count === 0 && $Empty->empty,
         description: 'Empty result count and empty views match no rows'
      );
   }
);
