<?php

use Bootgly\ADI\Table;

return [
   'describe' => 'It should find value (body) by column => value',

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
      // Test find operation
      $findResult = $Table->find(column: 0, value: 'Alice'); // Searching for 'Alice' in the first column
      assert(
         assertion: $findResult === true,
         description: 'Valid find result: ' . $findResult
      );

      // @ Invalid
      $findResult = $Table->find(column: 0, value: 'Test'); // Searching for 'Test' in the first column
      assert(
         assertion: $findResult === false,
         description: 'Invalid find result: ' . $findResult
      );

      return true;
   }
];
