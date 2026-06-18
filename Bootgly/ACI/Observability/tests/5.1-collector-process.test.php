<?php

use Bootgly\ACI\Observability\Collectors\Process;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Process collector reports memory, CPU, uptime and open fds, labelled by PID',
   test: function () {
      $Collector = new Process;
      $metrics = $Collector->collect();

      yield assert(
         assertion: isset(
            $metrics['process_memory_bytes'],
            $metrics['process_memory_peak_bytes'],
            $metrics['process_cpu_seconds_total'],
            $metrics['process_uptime_seconds'],
            $metrics['process_open_fds']
         ),
         description: 'all self-health metrics are present'
      );

      yield assert(
         assertion: $metrics['process_memory_bytes']['type'] === 'gauge'
            && $metrics['process_cpu_seconds_total']['type'] === 'counter',
         description: 'memory is a gauge and CPU is a counter'
      );

      $memory = $metrics['process_memory_bytes']['series'][0];
      yield assert(
         assertion: $memory['value'] > 0.0,
         description: 'memory usage is positive'
      );
      yield assert(
         assertion: $memory['labels'] === ['pid' => $memory['labels']['pid']]
            && $memory['labels']['pid'] !== '',
         description: 'series is labelled with a non-empty PID'
      );

      yield assert(
         assertion: $metrics['process_cpu_seconds_total']['series'][0]['value'] >= 0.0
            && $metrics['process_uptime_seconds']['series'][0]['value'] >= 0.0,
         description: 'CPU seconds and uptime are non-negative'
      );
   }
);
