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
      '1.1-preface_settings',
      '1.2-open_streams',
      '1.3-encode_roundtrip',
      '1.4-response_assembly',
      '1.5-flow_control',
      '1.6-goaway_rst',
      '1.7-negatives'
   ]
);
