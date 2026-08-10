<?php

use Bootgly\ACI\Tests\Suite\Test;


return new Test(
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
