<?php

use Bootgly\ACI\Observability\Data\Types;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Types enum exposes counter/gauge/histogram backing values',
   test: function () {
      yield assert(
         assertion: Types::Counter->value === 'counter',
         description: 'Counter backing value'
      );
      yield assert(
         assertion: Types::Gauge->value === 'gauge',
         description: 'Gauge backing value'
      );
      yield assert(
         assertion: Types::Histogram->value === 'histogram',
         description: 'Histogram backing value'
      );
   }
);
