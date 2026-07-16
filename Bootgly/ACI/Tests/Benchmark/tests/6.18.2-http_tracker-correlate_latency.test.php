<?php

use Bootgly\ACI\Tests\Benchmark\HTTP\Tracker;
use Bootgly\ACI\Tests\Benchmark\Latency\Histogram;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should correlate every final response with its logical request timestamp',
   test: function () {
      $Histogram = new Histogram;
      $Tracker = new Tracker(Histogram: $Histogram);
      $Tracker->send(1, 1_000_000);
      $Tracker->send(1, 2_000_000);

      $completed = $Tracker->feed(
         "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n"
         . "HTTP/1.1 201 Created\r\nContent-Length: 0\r\n\r\n",
         4_000_000,
      );
      $summary = $Histogram->inspect();
      $snapshot = $Tracker->inspect();

      yield assert(
         assertion: $completed === 2
            && $summary['count'] === 2
            && $summary['sum_ns'] === 5_000_000
            && $summary['min_ns'] === 2_000_000
            && $summary['max_ns'] === 3_000_000
            && $snapshot['responses'] === 2
            && $snapshot['statuses'] === [200 => 1, 201 => 1]
            && $snapshot['accounting'],
         description: 'One coalesced read retains two distinct request-to-response latencies',
      );

      $CensoredHistogram = new Histogram;
      $Censored = new Tracker(Histogram: $CensoredHistogram);
      $Censored->queue([10, 10]);
      $accepted = $Censored->accept(5, 5_000_000);
      $Censored->censor();
      $censored = $Censored->inspect();

      yield assert(
         assertion: $accepted === 1
            && $censored['sent'] === 1
            && $censored['failures'] === []
            && $censored['censors'] === ['measurement_ended' => 1]
            && $censored['write_failures'] === []
            && $censored['write_censors'] === ['measurement_ended' => 1]
            && $censored['accounting']
            && $censored['error'] === null
            && $CensoredHistogram->inspect()['count'] === 0,
         description: 'A partial pipeline separates response and write censoring from failures',
      );

      $missingTimestampRejected = false;
      try {
         (new Tracker(Histogram: new Histogram))->send();
      }
      catch (LogicException) {
         $missingTimestampRejected = true;
      }

      yield assert(
         assertion: $missingTimestampRejected,
         description: 'Enabled latency correlation fails closed without a send timestamp',
      );
   },
);
