<?php

namespace Bootgly\CLI\Terminal;

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
      '1.1-terminal-size-environment',
      '1.2-terminal-size-fallback',
      '2.1-cursor-position-shape',
      '3.1-progress-anchored-render',
      '4.1-menu-control-keys',
      '5.1-mouse-report-escapes',
      '6.1-input-reading-roles',
      '6.2-input-reading-timeout',
      '7.1-logs-render-width',
      '8.1-input-scan-line',
      '8.2-input-scan-echo',
      '8.3-input-scan-mask',
      '9.1-input-line-editing',
      '9.2-input-line-render',
      '10.1-viewport-clip',
      '11.1-screen-buffer-escapes',
      '11.2-screen-measure',
      '11.3-screen-watch',
      '12.1-input-listen-keys',
   ]
);
