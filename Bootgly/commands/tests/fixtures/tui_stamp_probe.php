<?php

use const Bootgly\CLI;
use Bootgly\ACI\Logs\Data\Record;

$root = getenv('BOOTGLY_TUI_STAMP_ROOT');
$base = getenv('BOOTGLY_TUI_STAMP_BASE');
if ($root === false || $base === false) {
   exit(2);
}

// ! Point the working/consumer side at the scratch base BEFORE autoboot
define('BOOTGLY_WORKING_BASE', $base);
define('BOOTGLY_WORKING_DIR', "$base/");
define('BOOTGLY_STORAGE_BASE', "$base/storage");

// This is an embedded fixture, not a Bootgly CLI command/script.
$_SERVER['SCRIPT_FILENAME'] = '';
require rtrim($root, '/') . '/autoboot.php';

// @ A bare TUI — no project, no launcher: nothing claimed an instance yet
$stamp = isset(Record::$qualifier) ? Record::$qualifier : null;
echo 'pre-reading:' . ($stamp === '' ? 'blank' : 'other') . ';';

// @ reading() claims the PID-qualified instance itself and forks the Client child
CLI->Terminal->Input->reading(
   static function ($read, $write): void {
      // # Client child: the stamp was set BEFORE the fork, so it names the parent
      $stamp = isset(Record::$qualifier) ? Record::$qualifier : null;
      echo 'child-stamp:' . ($stamp === (string) posix_getppid() ? 'pid' : 'no') . ';';
      $write('done');
      usleep(200000);
   },
   static function ($reading): void {
      // # Server parent: wait for the child's marker (bounded), then let reading() tear down
      $deadline = microtime(true) + 5.0;
      foreach ($reading(1024, 100000) as $chunk) {
         if (is_string($chunk) && str_contains($chunk, 'done')) {
            break;
         }
         if (microtime(true) > $deadline) {
            break;
         }
      }
   }
);

$stamp = isset(Record::$qualifier) ? Record::$qualifier : null;
echo 'parent-stamp:' . ($stamp === (string) posix_getpid() ? 'pid' : 'no') . ';';
