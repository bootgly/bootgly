<?php

use Bootgly\ABI\Code\__Array;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'It should return the boundary entries as {key, value} pairs',
   test: function () {
      // ! List
      $__Array = new __Array(['a', 'b', 'c']);

      $First = $__Array->First;
      $Last = $__Array->Last;

      yield assert(
         assertion: $First->key === 0,
         description: 'First key is: ' . $First->key
      );
      yield assert(
         assertion: $First->value === 'a',
         description: 'First value is: ' . $First->value
      );
      yield assert(
         assertion: $Last->key === 2,
         description: 'Last key is: ' . $Last->key
      );
      yield assert(
         assertion: $Last->value === 'c',
         description: 'Last value is: ' . $Last->value
      );

      // ! Reading is idempotent — no internal cursor to move
      yield assert(
         assertion: $__Array->First->value === 'a' && $__Array->First->value === 'a',
         description: 'Repeated First reads return the same entry'
      );

      // ! Associative — the key is preserved, not the position
      $__Array = new __Array(['a' => 1, 'b' => 2]);

      yield assert(
         assertion: $__Array->First->key === 'a' && $__Array->Last->key === 'b',
         description: 'Associative boundary keys are preserved'
      );

      // ! Empty
      $__Array = new __Array([]);

      yield assert(
         assertion: $__Array->First->key === null && $__Array->First->value === null,
         description: 'First of an empty array is {null, null}'
      );
      yield assert(
         assertion: $__Array->Last->key === null && $__Array->Last->value === null,
         description: 'Last of an empty array is {null, null}'
      );
   }
);
