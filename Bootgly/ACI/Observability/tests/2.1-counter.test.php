<?php

use Bootgly\ACI\Observability\Data\Types;
use Bootgly\ACI\Observability\Metrics\Counter;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Counter increments monotonically and rejects negative deltas',
   test: function () {
      $Counter = new Counter(name: 'http_requests_total', help: 'reqs', labels: ['method' => 'GET']);

      yield assert(
         assertion: $Counter->Type === Types::Counter,
         description: 'Counter reports the Counter type'
      );
      yield assert(
         assertion: $Counter->value === 0.0,
         description: 'Counter starts at zero'
      );

      $Counter->increment();
      $Counter->increment(by: 4);
      yield assert(
         assertion: $Counter->value === 5.0,
         description: 'increment() adds 1 then the given amount'
      );

      $sample = $Counter->read();
      yield assert(
         assertion: $sample['value'] === 5.0 && $sample['labels'] === ['method' => 'GET'],
         description: 'read() returns value + labels'
      );

      $threw = false;
      try {
         $Counter->increment(by: -1);
      }
      catch (\InvalidArgumentException) {
         $threw = true;
      }
      yield assert(
         assertion: $threw,
         description: 'negative increment throws InvalidArgumentException'
      );

      // # Observable counter bridges an externally-maintained total
      $external = 0;
      $Observable = new Counter(name: 'bytes_read_total', observe: function () use (&$external) {
         return $external;
      });
      $external = 4096;
      yield assert(
         assertion: $Observable->read()['value'] === 4096.0,
         description: 'observable counter pulls a live total at read()'
      );
   }
);
