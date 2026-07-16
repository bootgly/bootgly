<?php

namespace Bootgly\CLI\UX\Components;

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
      '1.1-form-fields',
      '1.2-form-sequence',
      '1.3-form-revert',
      '1.4-form-validation',
      '2.1-prompt-flow',
      '2.2-prompt-interruption',
      '2.3-prompt-mouse',
      '2.4-prompt-native',
      '3.1-tabs-add',
      '3.2-tabs-bar',
      '3.3-tabs-switch',
      '3.4-tabs-switching',
      '4.1-wizard-run',
      '4.2-wizard-add',
      '4.3-wizard-fail',
      '4.4-wizard-components',
      '5.1-dialog-open',
      '5.2-dialog-confirm',
      '5.3-dialog-cover',
      '5.4-dialog-prompt',
      '5.5-dialog-screen',
      '6.1-toasts-queue',
      '6.2-toasts-render',
      '6.3-toasts-reflow',
      '6.4-toasts-flash',
      '6.5-toasts-screen',
      '6.6-toasts-positions',
      '6.7-toasts-occupant',
      '7.1-filepicker-scan',
      '7.2-filepicker-pick',
      '8.1-finder-search',
      '8.2-finder-control',
      '8.3-finder-find',
   ]
);
