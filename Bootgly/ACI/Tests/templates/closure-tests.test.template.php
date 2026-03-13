<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: '',
   test: function () {
      // ...

      // Subtests
      #yield assert(...);

      return assert( // @phpstan-ignore-line
         assertion: false,
         description: null
      );
   }
);
