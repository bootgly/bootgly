<?php

use Bootgly\ABI\Code\__Array;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'It should alias the caller variable when bound by reference',
   test: function () {
      // ! Aliasing — writes cross in both directions
      $data = [1, 2, 3];
      $__Array = __Array::bind($data);

      $__Array->array[] = 'from object';
      yield assert(
         assertion: end($data) === 'from object',
         description: 'Caller sees a write made through the instance'
      );

      $data[] = 'from caller';
      yield assert(
         assertion: $__Array->Last->value === 'from caller',
         description: 'Instance sees a write made through the caller'
      );

      // ! Native in-place mutation reaches the caller's variable
      $numbers = [3, 1, 2];
      $Bound = __Array::bind($numbers);
      sort($Bound->array);
      yield assert(
         assertion: $numbers === [1, 2, 3],
         description: 'sort() through the binding sorts the caller variable'
      );

      // ! The constructor owns instead of aliasing
      $owned = [1, 2];
      $Owned = new __Array($owned);
      $Owned->array[] = 'only here';
      yield assert(
         assertion: $owned === [1, 2] && $Owned->array === [1, 2, 'only here'],
         description: 'The by-value constructor does not alias the caller'
      );

      // ! Binding an empty variable still tracks later growth
      $empty = [];
      $Empty = __Array::bind($empty);
      $empty[] = 'grown';
      yield assert(
         assertion: $Empty->First->value === 'grown' && $Empty->array === ['grown'],
         description: 'A binding follows the variable as it grows'
      );
   }
);
