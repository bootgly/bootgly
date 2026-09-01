<?php

namespace Bootgly\ACI\Logs;

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
      // # Levels + Record
      '1.1-levels',
      '1.2-record',
      // # Logger pipeline
      '2.1-logger-dispatch',
      '2.2-logger-validation',
      // # Formatters
      '3.1-formatter-line',
      '3.2-formatter-json',
      // # Processors
      '4.1-processors',
      // # Filters
      '5.1-filters',
      // # Handlers
      '6.1-handlers',
      // # File rotation
      '7.1-file-rotation',
      // # Viewer transport primitives (Record::import, Filter/Search, Handler/Pipe)
      '8.1-record-import',
      '8.2-filter-search',
      '8.3-handler-pipe',
      // # Live viewer
      '8.4-viewer',
      // # Provenance (project + instance fields)
      '9.1-record-provenance',
      '9.2-file-project-placeholder',
      '9.3-record-instance',
      // # Backlog (persisted-log reading + file-follow)
      '10.1-backlog-scan',
      '10.2-backlog-merge',
      '10.3-backlog-follow',
   ]
);
