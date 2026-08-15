<?php

use Bootgly\ABI\Code\__Array;
use Bootgly\ABI\Code\__Array\Pipeline;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'It should open a fused chain from the array it holds',
   test: function () {
      $Double = static fn (int $value): int => $value * 2;
      $Third = static fn (int $value): bool => $value % 3 === 0;

      // ! map() and filter() open a chain instead of running anything
      $__Array = new __Array([1, 2, 3, 4, 5, 6]);

      yield assert(
         assertion: $__Array->map($Double) instanceof Pipeline
            && $__Array->filter($Third) instanceof Pipeline,
         description: 'map() and filter() return a Pipeline'
      );

      // ! The chain agrees with the native idiom it replaces
      $source = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];
      $__Array = new __Array($source);

      yield assert(
         assertion: $__Array->map($Double)->filter($Third)->collect()
            === array_values(array_filter(array_map($Double, $source), $Third)),
         description: 'A chain returns exactly what the native chain returns'
      );

      // ! Opening a chain leaves the held array untouched
      $__Array = new __Array([1, 2, 3]);
      $__Array->map($Double)->collect();

      yield assert(
         assertion: $__Array->array === [1, 2, 3],
         description: 'A chain never mutates the array the instance holds'
      );

      // ! pipe() opens a reusable, source-less chain
      $Pipeline = __Array::pipe()->map($Double)->filter($Third);

      yield assert(
         assertion: $Pipeline->apply([1, 2, 3]) === [6]
            && $Pipeline->apply([4, 5, 6]) === [12],
         description: 'pipe() builds a pipeline reusable across arrays'
      );

      // ! A chain reads the bound variable as it stands when the chain opens
      $data = [1, 2, 3, 4, 5, 6];
      $Bound = __Array::bind($data);

      yield assert(
         assertion: $Bound->filter($Third)->collect() === [3, 6],
         description: 'A chain over a binding sees the caller variable'
      );
   }
);
