<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI;


use const BOOTGLY_ROOT_DIR;
use const PHP_BINARY;
use function array_merge;
use function array_values;
use function assert;
use function chmod;
use function fclose;
use function file_put_contents;
use function function_exists;
use function getenv;
use function is_resource;
use function mkdir;
use function posix_geteuid;
use function proc_close;
use function proc_open;
use function rmdir;
use function str_contains;
use function stream_get_contents;
use function symlink;
use function unlink;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;
use Bootgly\API\Environment\Agent;


return new Test(
   description: 'TestCommand registry: an unreadable registry is reported as unreadable, never as unregistered',
   // ? root reads through every permission bit — nothing would be denied
   skip: function_exists('posix_geteuid') === false || posix_geteuid() === 0,
   test: function () {
      // ? proc_open unavailable — nothing to spawn
      if (function_exists('proc_open') === false) {
         yield assert(assertion: true, description: 'Skipped: proc_open is unavailable');
         return;
      }
      // ? Nested probe guard
      if (getenv('BOOTGLY_TEST_REGISTRY_PROBE') === '1') {
         yield assert(assertion: true, description: 'Skipped: nested registry probe');
         return;
      }

      // ! Human environment
      $human = getenv();
      foreach ([...array_merge(...array_values(Agent::MARKERS)), 'AI_AGENT', 'BOOTGLY_AGENT_STDOUT_REDIRECTED', 'BOOTGLY_TTY'] as $variable) {
         unset($human[$variable]);
      }
      $human['BOOTGLY_TEST_REGISTRY_PROBE'] = '1';
      $human['BOOTGLY_TEST_SCOPE_PROBE'] = '1';
      $human['BOOTGLY_TEST_VERDICT_PROBE'] = '1';
      $human['BOOTGLY_TEST_VIEW_PROBE'] = '1';
      $human['BOOTGLY_TEST_HELP_PROBE'] = '1';

      // ! Fixture root
      $directory = Temporaries::reserve('testcommand-registry');
      $entry = "{$directory}/bootgly";
      $registry = "{$directory}/projects/Bootgly.projects.php";
      $locked = "{$directory}/locked";
      $target = "{$locked}/registry.php";
      $project = "{$directory}/projects/Distribute";

      // ! Runner
      $run = static function (string $cwd) use ($entry, $human): array {
         $Process = proc_open(
            [PHP_BINARY, $entry, 'test'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd,
            $human
         );
         if (is_resource($Process) === false) {
            return [-1, ''];
         }

         /** @var array<int,resource> $pipes */
         stream_get_contents($pipes[1]);
         $error = (string) stream_get_contents($pipes[2]);
         fclose($pipes[1]);
         fclose($pipes[2]);

         return [proc_close($Process), $error];
      };

      $root = BOOTGLY_ROOT_DIR;
      $source = "<?php\n\nreturn ['Distribute' => ['interfaces' => ['CLI']]];\n";
      $unreadable = "[test] project registry unreadable: {$registry} (Permission denied)";
      $unregistered = 'no registered project owns it';

      try {
         mkdir($project, 0o700, true);
         mkdir($locked, 0o700);
         file_put_contents(
            $entry,
            "<?php\n"
               . "define('BOOTGLY_WORKING_BASE', __DIR__);\n"
               . "define('BOOTGLY_WORKING_DIR', BOOTGLY_WORKING_BASE . DIRECTORY_SEPARATOR);\n"
               . "(include '{$root}autoboot.php') || exit(1);\n"
         );

         // @ stat() denied: the registry resolves into a directory the
         //   process may not search (the shape an SELinux denial produces)
         file_put_contents($target, $source);
         symlink('../locked/registry.php', $registry);
         chmod($locked, 0o600);
         [$status, $error] = $run($project);
         yield assert(
            assertion: $status !== 0
               && str_contains($error, $unreadable)
               && str_contains($error, $unregistered) === false,
            description: 'a registry that cannot be stat()ed is unreadable, not "no registered project owns it"'
         );

         [$status, $error] = $run("{$directory}/projects");
         yield assert(
            assertion: $status !== 0
               && str_contains($error, $unreadable)
               && str_contains($error, 'no registered project carries') === false,
            description: 'the same registry, from projects/, is unreadable, not "no registered project carries"'
         );
         chmod($locked, 0o700);
         unlink($registry);

         // @ stat() allowed, content unreadable (mode 000)
         file_put_contents($registry, $source);
         chmod($registry, 0);
         [$status, $error] = $run($project);
         yield assert(
            assertion: $status !== 0
               && str_contains($error, $unreadable)
               && str_contains($error, 'ErrorException') === false,
            description: 'a registry without read permission is reported as unreadable, without a raw error'
         );
         chmod($registry, 0o600);

         // @ Not a regular file (a device here; a FIFO takes the same path):
         //   never opened nor included
         unlink($registry);
         symlink('/dev/null', $registry);
         [$status, $error] = $run($project);
         yield assert(
            assertion: $status !== 0
               && str_contains($error, "[test] project registry unreadable: {$registry} (not a regular file)")
               && str_contains($error, 'did not return an array') === false,
            description: 'a registry that is not a regular file is refused before it is opened'
         );
         unlink($registry);

         // @ Read, but not an array: one message, no "unregistered" follow-up
         file_put_contents($registry, "<?php\n\nreturn 1;\n");
         [$status, $error] = $run($project);
         yield assert(
            assertion: $status !== 0
               && str_contains($error, 'did not return an array')
               && str_contains($error, $unregistered) === false,
            description: 'a registry that is not an array stops at its own message'
         );

         // @ Control: no registry at all keeps the "not registered" guidance
         unlink($registry);
         [$status, $error] = $run($project);
         yield assert(
            assertion: $status !== 0
               && str_contains($error, $unregistered)
               && str_contains($error, 'unreadable') === false,
            description: 'an absent registry still reports the directory as unregistered'
         );
      }
      finally {
         @chmod($locked, 0o700);
         @chmod($registry, 0o600);
         @unlink($registry);
         @unlink($target);
         @unlink($entry);
         foreach ([$locked, $project, "{$directory}/projects", $directory] as $path) {
            @rmdir($path);
         }
      }
   }
);
