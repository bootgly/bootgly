<?php

use Bootgly\ACI\Observability\Metrics\Counter;
use Bootgly\ACI\Observability\Metrics\Histogram;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Metric identity (name/help/labels) and histogram buckets are immutable after construction',
   test: function () {
      $Counter = new Counter(name: 'x', help: 'h', labels: ['a' => 'b']);

      $threwName = false;
      try {
         $Counter->name = 'y';
      }
      catch (\Error) {
         $threwName = true;
      }
      yield assert(
         assertion: $threwName && $Counter->name === 'x',
         description: 'name cannot be reassigned from outside (private(set))'
      );

      $Histogram = new Histogram(name: 'h', buckets: [0.1, 0.5, 1.0]);
      $threwBuckets = false;
      try {
         $Histogram->buckets = [9.9];
      }
      catch (\Error) {
         $threwBuckets = true;
      }
      yield assert(
         assertion: $threwBuckets && $Histogram->buckets === [0.1, 0.5, 1.0],
         description: 'histogram buckets cannot be reassigned (keeps $counts aligned)'
      );

      yield assert(
         assertion: $Counter->labels === ['a' => 'b'] && $Counter->help === 'h',
         description: 'identity remains publicly readable'
      );
   }
);
