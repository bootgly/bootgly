<?php

use Bootgly\ABI\Debugging\Shutdown;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Shutdown::collect() keeps only fatal error types',
   test: function () {
      $fatal = Shutdown::collect([
         'type' => E_ERROR,
         'message' => 'Allowed memory size exhausted',
         'file' => __FILE__,
         'line' => __LINE__
      ]);

      $parse = Shutdown::collect([
         'type' => E_PARSE,
         'message' => 'syntax error',
         'file' => __FILE__,
         'line' => __LINE__
      ]);

      $warning = Shutdown::collect([
         'type' => E_WARNING,
         'message' => 'fopen() failed',
         'file' => __FILE__,
         'line' => __LINE__
      ]);

      $empty = Shutdown::collect(['message' => 'typeless']);

      // :
      yield assert(
         assertion: $fatal === true,
         description: 'E_ERROR is collected'
      );
      yield assert(
         assertion: $parse === true,
         description: 'E_PARSE is collected'
      );
      yield assert(
         assertion: $warning === false,
         description: 'E_WARNING is ignored (already handled by Errors::collect)'
      );
      yield assert(
         assertion: $empty === false,
         description: 'entries without a type are ignored'
      );
   }
);
