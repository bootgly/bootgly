<?php


// * put uses here *


return [
   // @ configure
   'describe' => '',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      // ...

      // Subtests
      #yield assert(...);

      return assert( // @phpstan-ignore-line
         assertion: false,
         description: null
      );
   }
];
