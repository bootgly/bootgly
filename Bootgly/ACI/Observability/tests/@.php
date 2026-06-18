<?php

namespace Bootgly\ACI\Observability;

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
      // # Types
      '1.1-types',
      // # Instruments
      '2.1-counter',
      '2.2-gauge',
      '2.3-histogram',
      // # Registry + facade
      '3.1-registry',
      // # Snapshot transport (merge + import)
      '4.1-snapshot-merge',
      '4.2-snapshot-import',
      // # Collectors (self process + runtime health)
      '5.1-collector-process',
      '5.2-collector-runtime',
      '5.3-facade-collectors',
      // # Exporters (JSON encoding)
      '6.1-exporter-json',
      '6.2-exporter-empty',
      // # File-per-worker dump + merge-on-read aggregation
      '7.1-dump-aggregate',
      // # Consumers — Prometheus (pull) + OTLP (push) encoders
      '8.1-exporter-prometheus',
      '8.2-exporter-otlp',
      // # Hardening — immutability, real process uptime, export-failure safety
      '9.1-immutability',
      '9.2-process-uptime',
      '9.3-export-failure',
      // # Descriptor safety — bucket validation + merge type/schema guards
      '10.1-bucket-validation',
      '10.2-merge-guards',
   ]
);
