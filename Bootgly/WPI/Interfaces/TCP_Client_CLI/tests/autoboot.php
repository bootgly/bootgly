<?php


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
      '1.1-connect_bounded',
      '1.2-shutdown_hook_fork_isolation',
      '1.3-embedded_mode_isolation',
      '1.4-instance_state_isolation',
      '1.5-reactor_adoption',
      '2.1-pool_lifecycle',
      '2.2-pool_capacity',
      '2.3-pool_health',
      '3.1-writing_accounting',
      // # 1.4.x: the TLS passphrase never reaches a debug dump nor a stack trace
      '4.1-redaction_dumps',
      '4.2-redaction_traces',
      // # 1.4.x: the wire buffers of a connection never reach a debug dump
      '4.3-redaction_buffers',
      // # 1.4.x: a name is resolved before the dial, its addresses dialed in turn
      '5.1-dial_literals',
      '5.2-dial_names',
      '5.3-dial_fallback',
      '5.4-dial_budget',
      '5.5-suspects',
      '5.6-dial_tls',
      '5.7-dial_parked',
      '5.8-dial_edges',
   ]
);
