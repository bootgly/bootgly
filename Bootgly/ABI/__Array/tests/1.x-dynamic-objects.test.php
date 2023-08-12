<?php

use Bootgly\ABI\__Array;


return [
   // @ configure
   'describe' => '',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      // ...

      $__Array = new __Array(['a', 'b', 'c']);

      // * Meta
      // @ Pointer
      $Current = $__Array->Current;
      $Next = $__Array->Next;
      $Previous = $__Array->Previous;

      $Last = $__Array->Last;
      $First = $__Array->First;

      assert(
         assertion: $Current->key === 0,
         description: 'Current key is: ' . $Current->key
      );
      assert(
         assertion: $Current->value === 'a',
         description: 'Current value is: ' . $Current->value
      );

      assert(
         assertion: $Next->key === 1,
         description: 'Next key is: ' . $Next->key
      );
      assert(
         assertion: $Next->value === 'b',
         description: 'Next value is: ' . $Next->value
      );

      assert(
         assertion: $Previous->key === 0,
         description: 'Previous key is: ' . $Previous->key
      );
      assert(
         assertion: $Previous->value === 'a',
         description: 'Previous value is: ' . $Previous->value
      );

      assert(
         assertion: $Last->key === 2,
         description: 'Last key is: ' . $Last->key
      );
      assert(
         assertion: $Last->value === 'c',
         description: 'Last value is: ' . $Last->value
      );

      assert(
         assertion: $First->key === 0,
         description: 'First key is: ' . $First->key
      );
      assert(
         assertion: $First->value === 'a',
         description: 'First value is: ' . $First->value
      );

      return true;
   }
];
