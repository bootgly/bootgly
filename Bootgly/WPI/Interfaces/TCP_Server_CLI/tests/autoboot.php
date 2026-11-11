<?php

namespace Bootgly\WPI\Interfaces\TCP_Server_CLI;

use Bootgly\ACI\Tests\Suite;

return new Suite(
   // * Config
   autoBoot: __DIR__,
   autoInstance: true,
   autoReport: true,
   autoSummarize: true,
   exitOnFailure: true,
   // * Data
   suiteName: __NAMESPACE__,
   tests: [
      // # Live-log tap hub (IMP-7)
      '1.1-tap-attach',
      '1.2-tap-backpressure',
      '1.3-tap-fork-hygiene',
      '1.4-server-log-command',
      // # The root ownership handoff never follows a link (store/tap)
      '1.5-store-handoff',
      '1.6-store-root-handoff',
      '1.7-store-before-records',
      '1.8-inherit-launcher-hold',
      '1.9-privilege-source-pins',
      // # The Daemon fallback sink yields to a sink registered before start() (LOGS-10)
      '1.10-fallback-yields',
      // # 1.0.x (RH-H3-residual): a worker listener refused by the selector on resume() stays Paused and retries
      '1.11-resume-listener-refusal',
      // # 1.0.x (TCP-14): stats reset restores the declared error shape
      '1.12-stats-reset-shape',
      // # 1.0.x (TCP-24): a refused refork keeps the master and its slot
      '1.13-revive-fork-refusal',
      // # 1.0.x (TCP-24): a slot that keeps dying at boot is reforked after a capped backoff
      '1.14-revive-crash-backoff',
      // # 1.0.x (TCP-29, TCP-30): the Interactive prompt never spins on a non-terminal stdin and survives TAB
      '1.15-console-prompt',
      // # 1.0.x (TCP-24): a Daemon master — STDIN closed at detach — reforks a dead worker
      '1.16-daemon-refork',
      // # 1.0.x (H-HSC-3): one worker memory budget — footprint shares, epoch and memory_limit fit
      '1.17-worker_ledger_shares_fit',
      // # 1.0.x (H-HSC-5): an array begun as a list keeps the table PHP grows and converts it to (Buffers\Table, tally)
      '1.18-buffers_table_fill',
      // # 1.1.x (SESS-4, TEST-14): a root launch prepares cache/ and sessions/ or refuses; the demoted master can signal every worker
      '1.19-root-launch-storage-signals',
      // # 1.1.x (SRV-4): the cache-tree ancestor warning checks where a trusted link leads
      '1.20-ancestor-warning-links',
      // # 1.1.x (v1.1.0 worker recycling): maxRequests/jitter/maxMemory recycle a worker as a reload drain, never a crash
      '1.21-recycle-configs',
      '1.22-recycle-draw-count',
      '1.23-recycle-connections',
      '1.24-recycle-requests',
      // # 1.1.x (v1.1.0 worker recycling): the replacement serves while the old worker drains; boot exits stay crashes
      '1.25-recycle-overlap',
      '1.26-recycle-supervision',
      // # 1.1.x (v1.1.0 worker recycling): one retiree per slot (the gate), a deadline per retiree, no unreachable maxMemory
      '1.27-recycle-bound',
      // # 1.1.x (v1.1.0 worker recycling): a recycle inside a startup barrier is no lost worker
      '1.28-recycle-barrier',
      // # 1.1.x (v1.1.0 worker recycling): a replacement forked while paused boots paused
      '1.29-recycle-paused',
      // # 1.1.x (v1.1.0 worker recycling): beacon reads survive a handler, the WS mailbox follows the slot
      '1.30-recycle-units',
      // # 1.1.x (v1.1.0 worker recycling): a replacement past its limit holds its recycle until its slot's retiree is gone
      '1.31-recycle-gate',
      // # 1.1.x (v1.1.0 worker recycling): a worker forked without its beacon pair never recycles
      '1.32-recycle-beaconless',
      // # 1.3.x: the worker tickers (watchdog, recycle gate, drain poll) ride the loop's recurring timers — one timer owner
      '1.33-worker-timer-owner',
      // # 1.3.x: a worker takes the master's signals over on a loop that dispatches signals; a loop without the capability keeps the inherited handlers; a stop survives a throwing shutdown listener
      '1.34-worker-signal-owner',
   ]
);
