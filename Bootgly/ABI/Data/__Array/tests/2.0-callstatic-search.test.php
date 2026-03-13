<?php

use Bootgly\ABI\Data\__Array;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: '',
   test: function () {
      // ...

      $__Array = new __Array(['Bootgly', 'base', 'PHP', 'framework', 'to', 'Multi', 'Projects']);

      $Result = $__Array->search('framework');
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
   }
);
