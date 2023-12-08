<?php

use Bootgly\ABI\Data\__Array;


return [
   // @ configure
   'describe' => '',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      // ...

      $__Array = new __Array(['a', 'b', 'c']);

      // * Metadata
      // @ Pointer
      $Current = $__Array->Current;
      $Next = $__Array->Next;
      $Previous = $__Array->Previous;

      $Last = $__Array->Last;
      $First = $__Array->First;

      yield assert(
         assertion: $Current->key === 0,
         description: 'Current key is: ' . $Current->key
      );
      yield assert(
         assertion: $Current->value === 'a',
         description: 'Current value is: ' . $Current->value
      );

      yield assert(
         assertion: $Next->key === 1,
         description: 'Next key is: ' . $Next->key
      );
      yield assert(
         assertion: $Next->value === 'b',
         description: 'Next value is: ' . $Next->value
      );

      yield assert(
         assertion: $Previous->key === 0,
         description: 'Previous key is: ' . $Previous->key
      );
      yield assert(
         assertion: $Previous->value === 'a',
         description: 'Previous value is: ' . $Previous->value
      );

      yield assert(
         assertion: $Last->key === 2,
         description: 'Last key is: ' . $Last->key
      );
      yield assert(
         assertion: $Last->value === 'c',
         description: 'Last value is: ' . $Last->value
      );

      yield assert(
         assertion: $First->key === 0,
         description: 'First key is: ' . $First->key
      );
      yield assert(
         assertion: $First->value === 'a',
         description: 'First value is: ' . $First->value
      );
   }
];
