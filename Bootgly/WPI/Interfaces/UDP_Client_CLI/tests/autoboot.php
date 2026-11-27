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
      // # 1.4.x: embedded mode — instance state only, waits that park on a host loop
      '3.1-embedded_isolation',
      '3.2-embedded_instances',
      '3.3-embedded_host_loop',
      '3.4-embedded_teardown',
      '3.5-embedded_fresh_process',
      '3.6-ipv6_hosts',
      '3.7-embedded_unwatchable_socket',
   ]
);
