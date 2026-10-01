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
      '1.10-start_claim_release',
      // # 1.0.x (RH-H3-residual): a worker socket refused by the selector on resume() stays Paused and retries
      '1.11-resume-socket-refusal',
      // # 1.0.x (UDP-19): a reforked worker fields its signals and ticks its timers
      '1.12-refork_signal_state',
      // # 1.0.x (UDP-18): a socket the event backend refuses is refused loudly
      '1.13-socket_admission_refusal',
      // # 1.0.x (UDP-20): a blacklisted IP stops being served to its admitted peers
      '1.14-admitted_peer_blacklist',
      // # 1.0.x (UDP-9): a zero-length datagram is counted and never ends the drain
      '1.15-zero_length_datagram',
      // # 1.0.x (UDP-10): stats reset restores the declared error shape
      '1.16-stats_reset_shape',
      // # 1.0.x (UDP-19, UDP-21): only revive() forks a worker; every loop reaps only through a PID 1 reap()
      '1.17-master_loop_pins',
      // # 1.0.x (UDP-19): a reloaded master and its workers keep the launcher's mask
      '1.18-reload_signal_state',
      // # 1.0.x (UDP-19): a master running as PID 1 reaps the orphans it inherits
      '1.19-pid1_orphan_reap',
      // # 1.0.x (UDP-21): the console modes refork every worker exit and keep the master
      '1.20-console_mode_refork',
   ]
);
