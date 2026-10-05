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
      '1.1-handshake',
      '1.2-handshake-fallback',
      '2.1-frame',
      // # Cross-worker relay mailbox (WS-1)
      '3.1-relay',
      // # Relay fork topology (bus inheritance + both constructor roles)
      '3.2-relay_fork',
      // # Security H4 — compressed output must be bounded during inflation
      '4.1-decompression_limit',
      // # WS-6 — hot reloads never accumulate the bus sockets
      '3.3-relay_bus_reload',
      // # M4 — unfinished messages are time-bounded and charged to the worker ledger
      '5.1-message_deadline',
      '5.2-carry_budget',
      '5.3-reassembly_budget',
      '5.4-message_deadline_live',
      '5.5-inbound_share_eviction'
   ]
);
