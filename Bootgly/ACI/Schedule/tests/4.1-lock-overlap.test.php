<?php

use Bootgly\ACI\Schedule\Lock;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Lock: a second concurrent acquire() fails; a fresh one succeeds after release()',
   test: function () {
      $A = new Lock('lock_test');
      yield assert(
         assertion: $A->acquire() === true,
         description: 'First acquire() succeeds'
      );

      $B = new Lock('lock_test');
      yield assert(
         assertion: $B->acquire() === false,
         description: 'Second concurrent acquire() fails (overlap)'
      );

      $A->release();

      $C = new Lock('lock_test');
      yield assert(
         assertion: $C->acquire() === true,
         description: 'After release() a new lock can be acquired'
      );

      // @ Cleanup
      $C->release();
      $B->release();
   }
);
