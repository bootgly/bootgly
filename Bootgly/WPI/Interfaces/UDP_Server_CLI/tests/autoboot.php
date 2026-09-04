<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ACI\Tests\Suite;


return new Suite(
   // * Config
   autoBoot: __DIR__,
   autoInstance: true,
   autoReport: true,
   autoSummarize: true,
   exitOnFailure: true,
   suiteName: __NAMESPACE__,
   // * Data
   tests: [
      '1.1-connection_close_timer_release',
      '1.2-console',
      // # Peer admission before allocation (UDP-2)
      '1.3-accept_admission',
      // # Per-peer expire()/limit() watermarks (UDP-3)
      '1.4-expire_per_peer_watermark',
      // # Bounded peer admission, timer cardinality and dispatch (H7)
      '1.5-peer_admission_capacity',
      // # Public peer-protection configuration contract (H7)
      '1.6-peer_admission_configuration',
      // # Terminal callback wins over completed decode/write (H7)
      '1.7-close_during_pipeline',
      // # Terminal side effects remain charged to peer ceilings (H7)
      '1.8-retention_boundaries',
      // # Atomic admission commit under async reentry (H7)
      '1.9-admission_commit_race',
      // # Start claim and signal-mask release in master and worker (H7)
      '1.10-start_claim_release'
   ]
);
