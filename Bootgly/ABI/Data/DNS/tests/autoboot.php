<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Data\DNS\Tests;


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
      '1.1-dns-header',
      '1.2-dns-records',
      '1.3-dns-compression',
      '1.4-dns-captured-answers',
      '1.5-dns-malformed',
      '1.6-dns-truncated',
      '1.7-dns-encode-refusals',
      '1.8-dns-name-escapes',
      '1.9-dns-bounds',
      '1.10-dns-name',
      '1.11-dns-sweep',
   ]
);
