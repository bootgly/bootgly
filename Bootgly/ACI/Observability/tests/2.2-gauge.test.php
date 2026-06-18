<?php

use Bootgly\ACI\Observability\Metrics\Gauge;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Gauge sets/increments/decrements and supports observable callbacks',
   test: function () {
      $Gauge = new Gauge(name: 'workers_active');
      $Gauge->set(8.0);
      $Gauge->increment();
      $Gauge->decrement(by: 2);
      yield assert(
         assertion: $Gauge->value === 7.0,
         description: 'set + increment + decrement compose (8 + 1 - 2)'
      );

      $Observable = new Gauge(name: 'mem_bytes', observe: fn () => 1234);
      $sample = $Observable->read();
      yield assert(
         assertion: $sample['value'] === 1234.0,
         description: 'observable gauge pulls a live value at read()'
      );
   }
);
