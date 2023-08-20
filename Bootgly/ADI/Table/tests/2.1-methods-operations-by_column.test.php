<?php

use Bootgly\ADI\Table;

return [
   'describe' => 'It should test Table (body) operations by column',

   'test' => function () {
      // Create an instance of the Table class
      $Table = new Table;

      // Test setting header, body, and footer
      $Table->header = ['Name', 'Age'];
      $Table->body = [
         ['Alice', 25],
         ['Bob', 30],
         ['John', 21],
         ['Maria', 22],
      ];
      $Table->footer = ['Total', 0];

      // @ Valid
      // Test sum operation
      $sumResult = $Table->sum(column: 1); // Summing the values in the second column
      assert(
         assertion: $sumResult === 98,
         description: 'Invalid sum result: ' . $sumResult
      );
      // Test subtract operation
      $subtractResult = $Table->subtract(column: 1); // Subtracting the values in the second column
      assert(
         assertion: $subtractResult === -98,
         description: 'Invalid subtract result: ' . $subtractResult
      );
      // Test multiply operation
      $multiplyResult = $Table->multiply(column: 1); // Multiplying the values in the second column
      assert(
         assertion: $multiplyResult === 346500,
         description: 'Invalid multiply result: ' . $multiplyResult
      );
      // Test divide operation
      $divideResult = (int) $Table->divide(column: 1); // Dividing the values in the second column
      assert(
         assertion: $divideResult === 0,
         description: 'Invalid divide result: ' . $divideResult
      );

      // @ Invalid
      // Test sum on non-existent column
      $invalidSumResult = $Table->sum(0); // Summing non-existent column
      assert(
         assertion: $invalidSumResult === false,
         description: 'Invalid sum result for non-existent column:' . $invalidSumResult
      );

      return true;
   }
];
