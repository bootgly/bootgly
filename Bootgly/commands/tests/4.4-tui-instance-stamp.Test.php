<?php

namespace Bootgly\commands;


use const BOOTGLY_ROOT_BASE;
use const PHP_BINARY;
use function assert;
use function escapeshellarg;
use function glob;
use function mkdir;
use function rmdir;
use function shell_exec;
use function str_contains;
use function sys_get_temp_dir;
use function trim;
use function uniqid;
use function unlink;

use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'A bare TUI stamps its master PID as the record instance before forking the Client (no launcher involved)',
   test: function () {
      // ! Scratch working base: no project registry at all — Input::reading() owns the claim
      $base = sys_get_temp_dir() . '/bootgly-tuistamp-' . uniqid();
      mkdir("$base/storage", 0o755, true);

      $environment = 'BOOTGLY_TUI_STAMP_ROOT=' . escapeshellarg(BOOTGLY_ROOT_BASE)
         . ' BOOTGLY_TUI_STAMP_BASE=' . escapeshellarg($base);
      $fixture = escapeshellarg(__DIR__ . '/fixtures/tui_stamp_probe.php');
      $output = (string) shell_exec("$environment " . escapeshellarg(PHP_BINARY) . " $fixture 2>/dev/null");

      yield assert(
         assertion: str_contains($output, 'pre-reading:blank;'),
         description: 'before reading(), a process that claimed no instance is unstamped — got: ' . trim($output)
      );

      yield assert(
         assertion: str_contains($output, 'child-stamp:pid;'),
         description: 'the Client child inherits the master PID stamp (set before the fork)'
      );

      yield assert(
         assertion: str_contains($output, 'parent-stamp:pid;'),
         description: 'the master keeps its own PID as the instance after reading() returns'
      );

      // @ Cleanup
      foreach ((array) glob("$base/storage/pids/*") as $file) {
         @unlink((string) $file);
      }
      @rmdir("$base/storage/pids");
      @rmdir("$base/storage");
      @rmdir($base);
   }
);
