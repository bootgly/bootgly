<?php

namespace Bootgly\CLI\UI\Components;

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
      '2.1-question-ask',
      '2.2-question-validation',
      '2.3-question-mask',
      '2.4-question-confirm',
      '3.1-menu-enter-confirm',
      '3.2-menu-viewport',
      '3.3-menu-typeahead',
      '3.4-menu-columns',
      '4.1-spinner-frames',
      '5.1-timer-countdown',
      '5.2-timer-handler',
      '6.1-timeline-states',
      '6.2-timeline-render',
      '6.3-timeline-return',
      '6.4-timeline-insert',
      '7.1-progress-bars',
      '7.2-progress-columns',
      '8.1-chart-sparkline',
      '8.2-chart-bars',
      '9.1-text-effects',
      '10.1-question-suggestions',
      '11.1-textarea-edit',
      '13.1-scrollarea-scroll',
      '13.2-scrollarea-pointer',
      '14.1-chart-gradient',
      '14.2-charts-meter',
      '14.3-charts-graph',
      '15.1-frame-buffer',
      '15.2-frame-render',
      '15.3-frame-diff',
      '16.1-grid-arrange',
      '16.2-grid-resize',
      '15.4-frame-drain',
      '16.3-grid-boxing',
      '17.1-table-hosted',
      '18.1-tree-build',
      '18.2-tree-render',
      '18.3-tree-control',
      '18.4-tree-lazy',
      '18.5-tree-viewport',
      '18.6-tree-navigate',
   ]
);
