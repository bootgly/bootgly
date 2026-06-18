<?php

use Bootgly\ACI\Observability\Metrics\Histogram;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Histogram rejects empty, non-finite, and duplicate bucket bounds',
   test: function () {
      $cases = [
         'empty'     => [],
         'NAN'       => [0.1, NAN, 1.0],
         'INF'       => [0.1, INF],
         'duplicate' => [0.5, 0.5, 1.0],
      ];

      foreach ($cases as $label => $buckets) {
         $threw = false;
         try {
            new Histogram(name: 'h', buckets: $buckets);
         }
         catch (\InvalidArgumentException) {
            $threw = true;
         }
         yield assert(
            assertion: $threw,
            description: "rejects $label buckets"
         );
      }

      // # Valid buckets still construct (and sort)
      $Histogram = new Histogram(name: 'ok', buckets: [1.0, 0.1, 0.5]);
      yield assert(
         assertion: $Histogram->buckets === [0.1, 0.5, 1.0],
         description: 'valid buckets construct and sort ascending'
      );
   }
);
