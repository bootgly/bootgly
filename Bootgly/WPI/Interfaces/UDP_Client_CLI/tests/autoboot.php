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
      '1.1-writing_one_shot_delivery',
      // # 1.4.x: the datagram buffers of a connection never reach a debug dump
      '2.1-redaction_buffers',
   ]
);
