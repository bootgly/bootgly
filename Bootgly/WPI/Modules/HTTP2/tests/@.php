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
      '1.1-frame_pack',
      '1.2-settings_parse',
      '1.3-settings_pack',
      '2.1-hpack_decode_literals',
      '2.2-hpack_decode_requests',
      '2.3-hpack_decode_responses',
      '2.4-hpack_edges',
      '2.5-hpack_encode'
   ]
);
