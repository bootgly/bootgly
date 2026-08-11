<?php

namespace Bootgly\CLI\UI\Base;

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
      '1.1-frame-buffer',
      '1.2-frame-render',
      '1.3-frame-diff',
      '1.4-frame-drain',
      '2.1-fieldset-render',
      '3.1-flyout-render',
      '4.1-listbox-render',
      '5.1-tabs-add',
      '5.2-tabs-bar',
      '5.3-tabs-switch',
      '5.4-tabs-switching',
      '5.5-tabs-pointer',
   ]
);
