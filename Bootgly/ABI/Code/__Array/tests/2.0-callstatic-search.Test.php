<?php

use Bootgly\ABI\Code\__Array;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'It should search values and report {key, value, found}',
   test: function () {
      // ! Found
      $array = ['Bootgly', 'base', 'PHP', 'framework', 'to', 'Multi', 'Projects'];

      $Result = __Array::search($array, 'framework');
      yield assert(
         assertion: $Result->key === 3,
         description: 'Found key is: ' . $Result->key
      );
      yield assert(
         assertion: $Result->value === 'framework',
         description: 'Found value is: ' . $Result->value
      );
      yield assert(
         assertion: $Result->found === true,
         description: 'Result not found!'
      );

      // ! Not found
      $Result = __Array::search($array, 'absent');
      yield assert(
         assertion: $Result->found === false && $Result->key === false && $Result->value === null,
         description: 'A missing needle reports {false, null, false}'
      );

      // ! Loose match returns the value it matched (STR-3)
      $Result = __Array::search([1, 2, 3], '3');
      yield assert(
         assertion: $Result->found === true && $Result->key === 2 && $Result->value === 3,
         description: 'Loose match returns the matched value, not false'
      );

      // ! Strict match rejects the cross-type needle
      $Result = __Array::search([1, 2, 3], '3', strict: true);
      yield assert(
         assertion: $Result->found === false,
         description: 'Strict search rejects a cross-type needle'
      );

      // ! A list of needles is tried in order
      $Result = __Array::search($array, ['absent', 'PHP']);
      yield assert(
         assertion: $Result->found === true && $Result->value === 'PHP',
         description: 'The first matching needle of a list wins'
      );
   }
);
