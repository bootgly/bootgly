<?php

use Bootgly\ACI\Queues\Backoffs;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'backoff policies compute the expected retry delay',
   test: function () {
      yield assert(
         assertion: Backoffs::Fixed->delay(3, 10) === 10,
         description: 'Fixed ignores the attempt number'
      );
      yield assert(
         assertion: Backoffs::Linear->delay(3, 10) === 30,
         description: 'Linear scales linearly with the attempt'
      );
      yield assert(
         assertion: Backoffs::Exponential->delay(1, 10) === 10,
         description: 'Exponential attempt 1 = base'
      );
      yield assert(
         assertion: Backoffs::Exponential->delay(2, 10) === 20,
         description: 'Exponential attempt 2 = base * 2'
      );
      yield assert(
         assertion: Backoffs::Exponential->delay(4, 10) === 80,
         description: 'Exponential attempt 4 = base * 8'
      );
   }
);
