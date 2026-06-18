<?php

use Bootgly\ACI\Observability\Collectors\Runtime;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Runtime collector reports GC counters and included-file count',
   test: function () {
      $Collector = new Runtime;
      $metrics = $Collector->collect();

      yield assert(
         assertion: isset(
            $metrics['runtime_gc_runs_total'],
            $metrics['runtime_gc_collected_total'],
            $metrics['runtime_included_files']
         ),
         description: 'GC counters and included-files gauge are present'
      );

      yield assert(
         assertion: $metrics['runtime_gc_runs_total']['type'] === 'counter'
            && $metrics['runtime_included_files']['type'] === 'gauge',
         description: 'GC runs is a counter and included files is a gauge'
      );

      yield assert(
         assertion: $metrics['runtime_included_files']['series'][0]['value'] > 0.0,
         description: 'at least one file has been included'
      );
   }
);
