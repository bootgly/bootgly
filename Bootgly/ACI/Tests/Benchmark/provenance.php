#!/usr/bin/env php
<?php

use Bootgly\ACI\Tests\Benchmark\Provenance;

require __DIR__ . '/Provenance.php';

$docker = isset($argv[3]) && $argv[3] === '--docker-build-args';
if ((count($argv) !== 3 && count($argv) !== 4) || (isset($argv[3]) && $docker === false)) {
   fwrite(
      STDERR,
      "Usage: provenance.php <framework-root> <benchmarks-root> [--docker-build-args]\n"
   );
   exit(64);
}

$metadata = Provenance::collect($argv[1], $argv[2]);
if (Provenance::validate($metadata) === false) {
   fwrite(STDERR, "Source provenance is incomplete or unsupported.\n");
   exit(65);
}

foreach ($metadata as $key => $value) {
   if ($docker) {
      if ($key === 'source-identity-version') {
         continue;
      }

      echo '--build-arg=BOOTGLY_', strtoupper(str_replace('-', '_', $key)), '=', $value, "\n";
   }
   else {
      echo str_replace('-', '_', $key), '=', $value, "\n";
   }
}
