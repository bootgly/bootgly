#!/usr/bin/env php
<?php

use Bootgly\ACI\Tests\Benchmark\Provenance;

require dirname(__DIR__, 2) . '/Bootgly/ACI/Tests/Benchmark/Provenance.php';

// ! CLI arguments from `$_SERVER['argv']` (`$argv` may be unset: PHP 8.5's CLI
//   reports register_argc_argv=0); a missing list falls into the usage below
/** @var array<int,string> $arguments */
$arguments = $_SERVER['argv'] ?? [];
$docker = isset($arguments[3]) && $arguments[3] === '--docker-build-args';
if ((count($arguments) !== 3 && count($arguments) !== 4) || (isset($arguments[3]) && $docker === false)) {
   fwrite(
      STDERR,
      "Usage: provenance.php <framework-root> <benchmarks-root> [--docker-build-args]\n"
   );
   exit(64);
}

$metadata = Provenance::collect($arguments[1], $arguments[2]);
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
