<?php

use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Project::boot() stamps the process log provenance (Record::$provenance)',
   test: function () {
      // ! boot() defines BOOTGLY_PROJECT once per process, so the wire is
      //   exercised in child processes running the provenance fixture
      $fixture = __DIR__ . '/fixtures/provenance_probe.php';
      $environment = 'BOOTGLY_PROVENANCE_ROOT=' . escapeshellarg(BOOTGLY_ROOT_BASE);
      $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture) . ' 2>/dev/null';

      // # Bare child — no project booted
      $bare = shell_exec("$environment $command");
      yield assert(
         assertion: $bare === 'framework',
         description: 'a process without a booted project keeps the framework provenance'
      );

      // # Booted child — the boot closure observes the stamped canonical folder id
      $booted = shell_exec("$environment BOOTGLY_PROVENANCE_BOOT=1 $command");
      yield assert(
         assertion: $booted === 'Prov/Sample',
         description: 'Project::boot() stamps the canonical folder id before the boot closure runs'
      );
   }
);
