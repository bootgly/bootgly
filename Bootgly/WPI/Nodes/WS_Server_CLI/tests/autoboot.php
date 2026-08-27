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
      '4.1-decompression_limit'
   ]
);
