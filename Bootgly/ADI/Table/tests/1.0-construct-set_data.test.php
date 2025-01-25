<?php

use Bootgly\ADI\Table;

return [
   'describe' => 'It should set Table Data: header, body and footer',

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
      // Test header
      $header0 = $Table->Header[0];
      yield assert(
         assertion: $header0[0] === 'Name',
         description: 'Header 0 - Column 0:' . $header0[0]
      );
      yield assert(
         assertion: $header0[1] === 'Age',
         description: 'Header 0 - Column 1: ' . $header0[1]
      );
      // Test body
      $body0 = $Table->Body[0];
      yield assert(
         assertion: $body0[0] === 'Alice',
         description: 'Body 0 - Column 0:' . $body0[0]
      );
      yield assert(
         assertion: $body0[1] === 25,
         description: 'Body 0 - Column 1: ' . $body0[1]
      );
      // Test footer
      $footer0 = $Table->Footer[0];
      yield assert(
         assertion: $footer0[0] === 'Total',
         description: 'Footer 0 - Column 0:' . $footer0[0]
      );
      yield assert(
         assertion: $footer0[1] === 0,
         description: 'Footer 0 - Column 1: ' . $footer0[1]
      );
      // @ Invalid
      // ...
   }
];
