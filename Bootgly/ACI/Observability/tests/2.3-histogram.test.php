<?php

use Bootgly\ACI\Observability\Metrics\Histogram;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Histogram buckets observations cumulatively with sum and count',
   test: function () {
      $Histogram = new Histogram(name: 'req_duration_seconds', buckets: [0.1, 0.5, 1.0]);
      $Histogram->observe(0.05);
      $Histogram->observe(0.3);
      $Histogram->observe(0.7);
      $Histogram->observe(5.0);

      $sample = $Histogram->read();

      yield assert(
         assertion: $sample['buckets']['0.1'] === 1,
         description: 'le=0.1 counts only the 0.05 observation'
      );
      yield assert(
         assertion: $sample['buckets']['0.5'] === 2,
         description: 'le=0.5 is cumulative (0.05 + 0.3)'
      );
      yield assert(
         assertion: $sample['buckets']['1'] === 3,
         description: 'le=1 is cumulative (0.05 + 0.3 + 0.7)'
      );
      yield assert(
         assertion: $sample['buckets']['+Inf'] === 4,
         description: '+Inf equals the total observation count'
      );
      yield assert(
         assertion: $sample['count'] === 4 && abs($sample['sum'] - 6.05) < 1e-9,
         description: 'count and sum aggregate every observation'
      );
   }
);
