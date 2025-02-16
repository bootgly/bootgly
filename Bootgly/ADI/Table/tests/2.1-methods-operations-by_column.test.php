<?php

use Bootgly\ADI\Table;

return [
   'describe' => 'It should test Table (body) operations by column',

   'test' => function () {
      // Create an instance of the Table class
      $Table = new Table;

      // Test setting header, body, and footer
      $Table->Header->set([['Name', 'Age']]);
      $Table->Body->set([
         ['Alice', 25],
         ['Bob', 30],
         ['John', 21],
         ['Maria', 22],
      ]);
      $Table->Footer->set([['Total', 0]]);

      // @ Valid
      // Test sum operation
      $sumResult = $Table->sum(column: 1); // Summing the values in the second column
      yield assert(
         assertion: $sumResult === 98,
         description: 'Invalid sum result: ' . $sumResult
      );
      // Test subtract operation
      $subtractResult = $Table->subtract(column: 1); // Subtracting the values in the second column
      yield assert(
         assertion: $subtractResult === -98,
         description: 'Invalid subtract result: ' . $subtractResult
      );
      // Test multiply operation
      $multiplyResult = $Table->multiply(column: 1); // Multiplying the values in the second column
      yield assert(
         assertion: $multiplyResult === 346500,
         description: 'Invalid multiply result: ' . $multiplyResult
      );
      // Test divide operation
      $divideResult = (int) $Table->divide(column: 1); // Dividing the values in the second column
      yield assert(
         assertion: $divideResult === 0,
         description: 'Invalid divide result: ' . $divideResult
      );

      // @ Invalid
      // Test sum on non-existent column
      $invalidSumResult = $Table->sum(0); // Summing non-existent column
      yield assert(
         assertion: $invalidSumResult === false,
         description: 'Invalid sum result for non-existent column:' . $invalidSumResult
      );
   }
];
