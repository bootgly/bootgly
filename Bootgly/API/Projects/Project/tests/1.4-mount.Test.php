<?php

use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Project::mount() prepares the environment without running the entry closure',
   test: function () {
      // ! mount() defines BOOTGLY_PROJECT once per process — exercised in a child
      $fixture = __DIR__ . '/fixtures/mount_probe.php';
      $environment = 'BOOTGLY_MOUNT_ROOT=' . escapeshellarg(BOOTGLY_ROOT_BASE);
      $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture) . ' 2>/dev/null';

      $output = (string) shell_exec("$environment $command");

      yield assert(
         assertion: str_contains($output, 'constant:yes;'),
         description: 'mount() defines BOOTGLY_PROJECT as this project — got: ' . trim($output)
      );

      yield assert(
         assertion: str_contains($output, 'provenance:Mount/Sample;'),
         description: 'mount() stamps the log provenance with the canonical folder id'
      );

      yield assert(
         assertion: str_contains($output, 'entry-ran;') === false,
         description: 'the boot entry closure never runs on a mount'
      );

      yield assert(
         assertion: str_contains($output, 'reboot:refused;'),
         description: 'a boot() after mount() throws — one project per process'
      );
   }
);
