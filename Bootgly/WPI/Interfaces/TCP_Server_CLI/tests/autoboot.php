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
   ]
);
