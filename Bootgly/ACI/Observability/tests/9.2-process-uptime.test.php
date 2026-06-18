<?php

use Bootgly\ACI\Observability\Collectors\Process;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Process uptime reflects the real process start, not when the collector was constructed',
   test: function () {
      $Early = new Process;
      usleep(80_000); // 80ms
      $Late = new Process;

      // @ Read both back-to-back: both sample /proc at ~the same wall time
      $early = $Early->collect()['process_uptime_seconds']['series'][0]['value'];
      $late  = $Late->collect()['process_uptime_seconds']['series'][0]['value'];

      yield assert(
         assertion: $early > 0.0 && $late > 0.0,
         description: 'uptime is positive'
      );

      // If uptime were "since construction", $early would exceed $late by ~80ms. Read from /proc it
      // reflects the process start, so two collectors built 80ms apart report near-equal uptime.
      yield assert(
         assertion: abs($early - $late) < 0.05,
         description: 'uptime is independent of collector construction time (≈ process uptime)'
      );
   }
);
