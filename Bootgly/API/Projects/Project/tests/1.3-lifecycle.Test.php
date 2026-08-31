<?php

use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Project.Boot precedes the boot closure; Project.Shutdown honors the handoff mark',
   test: function () {
      // ! boot() defines BOOTGLY_PROJECT once per process, so the lifecycle
      //   is exercised in child processes running the fixture
      $fixture = __DIR__ . '/fixtures/lifecycle_probe.php';
      $environment = 'BOOTGLY_LIFECYCLE_ROOT=' . escapeshellarg(BOOTGLY_ROOT_BASE);
      $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($fixture) . ' 2>/dev/null';

      // # A server-shaped closure exits the process and never returns
      $server = shell_exec("$environment BOOTGLY_LIFECYCLE_MODE=exit $command");
      yield assert(
         assertion: $server === "boot-event\nclosure\nshutdown-event\n",
         description: 'Boot is announced before the closure runs and Shutdown at process exit'
      );

      // # A handoff process (daemonize launcher, reload exec, helper child)
      $handoff = shell_exec("$environment BOOTGLY_LIFECYCLE_MODE=detach $command");
      yield assert(
         assertion: $handoff === "boot-event\nclosure\n",
         description: 'a detached process never announces Shutdown — its exit is a handoff'
      );
   }
);
