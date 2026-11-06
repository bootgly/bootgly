<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\commands;


use const BOOTGLY_ROOT_BASE;
use const PHP_BINARY;
use function assert;
use function escapeshellarg;
use function explode;
use function fclose;
use function fgets;
use function file_put_contents;
use function getenv;
use function glob;
use function is_resource;
use function mkdir;
use function posix_kill;
use function proc_close;
use function proc_open;
use function rmdir;
use function shell_exec;
use function str_contains;
use function str_starts_with;
use function sys_get_temp_dir;
use function trim;
use function uniqid;
use function unlink;
use function usleep;

use Bootgly\ACI\Tests\Suite\Test;


/**
 * `project stop` ends a server's retirees — workers still draining a
 * recycle, listed apart from the serving workers in its state — with the
 * rest: when the master does not answer SIGTERM, the stop freezes it and ends
 * every child it authenticates, retirees included, and success means the
 * retiree is gone too.
 */

return new Test(
   description: '`project stop`: a master that ignores SIGTERM has its retirees ended with its workers',
   test: function () {
      // ! Scratch working base: registry + one project + its pids directory
      $temporary = sys_get_temp_dir();
      $unique = uniqid();
      $base = "{$temporary}/bootgly-stopretirees-{$unique}";
      mkdir("$base/projects/Scratch", 0o775, true);
      mkdir("$base/storage/pids", 0o755, true);
      file_put_contents(
         "$base/projects/Bootgly.projects.php",
         "<?php return ['Scratch' => ['interfaces' => ['WPI']]];"
      );
      file_put_contents("$base/projects/Scratch/Scratch.Project.php", <<<'PROJECT'
      <?php

      use Bootgly\API\Projects\Project;

      return new Project(
         boot: static function (): void {},
         exportable: false,
         name: 'Scratch'
      );
      PROJECT);

      $master = 0;
      $retiree = 0;
      try {
         // @ A detached stand-in master with one retiree, deaf to SIGTERM
         $Launcher = proc_open(
            [PHP_BINARY, __DIR__ . '/fixtures/retiree_probe.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            [
               'BOOTGLY_RETIREE_PROBE_ROOT' => BOOTGLY_ROOT_BASE,
               'BOOTGLY_RETIREE_PROBE_BASE' => $base,
               'BOOTGLY_RETIREE_PROBE_PORT' => '9011',
               'PATH' => (string) getenv('PATH'),
            ]
         );
         $ready = is_resource($Launcher) ? (string) fgets($pipes[1]) : '';
         if (is_resource($Launcher)) {
            foreach ($pipes as $pipe) {
               is_resource($pipe) && fclose($pipe);
            }
            proc_close($Launcher);
         }
         if (str_starts_with($ready, 'ready ')) {
            [, $first, $second] = explode(' ', trim($ready)) + [0, '0', '0'];
            $master = (int) $first;
            $retiree = (int) $second;
         }

         yield assert(
            assertion: $master > 1 && $retiree > 1 && posix_kill($master, 0) && posix_kill($retiree, 0),
            description: 'the stand-in master and its retiree are up, the retiree listed in the state'
         );

         // @ Stop it, exactly as `bootgly project Scratch stop` would
         $root = escapeshellarg(BOOTGLY_ROOT_BASE);
         $scratch = escapeshellarg($base);
         $binary = escapeshellarg(PHP_BINARY);
         $fixture = escapeshellarg(__DIR__ . '/fixtures/stop_probe.php');
         $output = (string) shell_exec(
            "COLUMNS=200 BOOTGLY_STOP_PROBE_ROOT={$root} BOOTGLY_STOP_PROBE_BASE={$scratch} BOOTGLY_STOP_PROBE_VERB=stop {$binary} {$fixture} 2>&1"
         );

         // ! init reaps the killed ones; give the process table a moment
         $alive = true;
         for ($i = 0; $alive && $i < 30; $i++) {
            $alive = posix_kill($master, 0) || posix_kill($retiree, 0);
            if ($alive) {
               usleep(100000);
            }
         }

         yield assert(
            assertion: str_contains($output, 'result:true') && str_contains($output, 'SUCCESS'),
            description: "the stop succeeds — got: {$output}"
         );
         yield assert(
            assertion: $alive === false,
            description: 'the master and its retiree are both gone'
         );
      }
      finally {
         // @ Cleanup — any stand-in left, then the tree
         foreach ([$retiree, $master] as $PID) {
            if ($PID > 1 && posix_kill($PID, 0)) {
               posix_kill($PID, 9);
            }
         }
         foreach ((array) glob("$base/storage/pids/*") as $file) {
            @unlink((string) $file);
         }
         @rmdir("$base/storage/pids");
         @rmdir("$base/storage");
         @unlink("$base/projects/Scratch/Scratch.Project.php");
         @unlink("$base/projects/Bootgly.projects.php");
         @rmdir("$base/projects/Scratch");
         @rmdir("$base/projects");
         @rmdir($base);
      }
   }
);
