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


use const BOOTGLY_WORKING_DIR;
use function assert;
use function chmod;
use function count;
use function escapeshellarg;
use function exec;
use function file_get_contents;
use function file_put_contents;
use function function_exists;
use function getmypid;
use function implode;
use function is_dir;
use function is_file;
use function is_string;
use function mkdir;
use function posix_geteuid;
use function rmdir;
use function str_contains;
use function str_ends_with;
use function sys_get_temp_dir;
use function unlink;
use ReflectionMethod;

use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'The global wrapper resolves the nearest trusted workspace in ordinary mode',
   skip: function_exists('posix_geteuid') && posix_geteuid() === 0,
   test: function () {
      // ! The recorded script is only a fallback. install() records the active
      //   working launcher so a Kit does not silently fall back to its internal
      //   framework checkout. Ordinary runs still walk up from $PWD first.
      $Compose = new ReflectionMethod(SetupCommand::class, 'compose');
      $Command = new SetupCommand;

      // ! Outside the framework checkout: every ancestor of a fixture inside
      //   the repository carries the framework's own launcher, which the
      //   walk-up would (correctly) find — the fallback case needs a cwd with
      //   no launcher above it.
      $root = sys_get_temp_dir() . '/bootgly-wrapper-' . getmypid();
      $paths = [
         "{$root}/kit/projects/App",
         "{$root}/plain",
         "{$root}/install",
      ];
      foreach ($paths as $path) {
         if (is_dir($path) === false) {
            mkdir($path, 0755, true);
         }
      }

      $erase = function () use ($root): void {
         foreach ([
            "{$root}/kit/projects/App/bootgly", "{$root}/kit/projects/App", "{$root}/kit/projects",
            "{$root}/kit/bootgly", "{$root}/kit",
            "{$root}/plain", "{$root}/php", "{$root}/fallback", "{$root}/wrapper", "{$root}/hostile",
            "{$root}/install/bootgly", "{$root}/install",
            $root,
         ] as $target) {
            if (is_dir($target) === true) {
               rmdir($target);
            }
            elseif (is_file($target) === true) {
               unlink($target);
            }
         }
      };

      try {
         // # A kit launcher — the signal the walk-up looks for
         file_put_contents(
            "{$root}/kit/bootgly",
            "#!/usr/bin/env php\n<?php\ndefine('BOOTGLY_WORKING_BASE', __DIR__);\n"
         );
         // # A stand-in interpreter that prints its argv, one per line
         file_put_contents("{$root}/php", "#!/bin/sh\nprintf '%s\\n' \"\$@\"\n");
         chmod("{$root}/php", 0755);
         // # The recorded fallback (its content never runs here)
         file_put_contents("{$root}/fallback", '');

         $wrapper = $Compose->invoke($Command, "{$root}/php", "{$root}/fallback");
         file_put_contents("{$root}/wrapper", $wrapper);
         chmod("{$root}/wrapper", 0755);

         $run = static function (string $cwd, string $environment = '') use ($root): array {
            $output = [];
            exec(
               "cd " . escapeshellarg($cwd) . " && {$environment} " . escapeshellarg("{$root}/wrapper") . " 2>/dev/null",
               $output
            );

            return $output;
         };

         // @@ Inside the kit (deep): the nearest launcher wins
         $argv = $run("{$root}/kit/projects/App");
         $expected = "{$root}/kit/bootgly";

         yield assert(
            assertion: ($argv[count($argv) - 1] ?? '') === $expected,
            description: 'ordinary mode resolves the kit launcher — got: '
               . implode(' | ', $argv)
         );

         // @@ No launcher above: the recorded fallback runs
         $argv = $run("{$root}/plain");

         yield assert(
            assertion: ($argv[count($argv) - 1] ?? '') === "{$root}/fallback",
            description: 'with no launcher above the cwd, the wrapper falls back to the recorded script — got: ' . implode(' | ', $argv)
         );

         // @@ BOOTGLY_JIT=0 keeps both the opt-out flag and the resolution
         $argv = $run("{$root}/kit/projects/App", 'BOOTGLY_JIT=0');

         yield assert(
            assertion: str_contains(implode(' ', $argv), 'opcache.jit=disable')
               && ($argv[count($argv) - 1] ?? '') === $expected,
            description: 'BOOTGLY_JIT=0 preserves ordinary launcher resolution — got: '
               . implode(' | ', $argv)
         );

         // @@ A launcher anyone else could have written is NOT trusted: the
         //    nearer 0666 file is skipped and the kit's own launcher still wins
         file_put_contents(
            "{$root}/kit/projects/App/bootgly",
            "<?php\ndefine('BOOTGLY_WORKING_BASE', __DIR__);\n"
         );
         chmod("{$root}/kit/projects/App/bootgly", 0666);
         $argv = $run("{$root}/kit/projects/App");
         yield assert(
            assertion: ($argv[count($argv) - 1] ?? '') === $expected,
            description: 'a group/other-writable launcher is skipped — got: '
               . implode(' | ', $argv)
         );
         // @@ The recorded paths are data, never shell: a `$(…)` or a quote in
         //    the checkout path must survive `bash -n` and stay literal
         $hostile = $Compose->invoke($Command, "{$root}/php", "{$root}/a\$(touch pwned)\"b/bootgly");
         file_put_contents("{$root}/hostile", $hostile);
         $syntax = [];
         exec('bash -n ' . escapeshellarg("{$root}/hostile") . ' 2>&1; echo "status=$?"', $syntax);
         yield assert(
            assertion: ($syntax[count($syntax) - 1] ?? '') === 'status=0'
               && str_contains($hostile, "SCRIPT='{$root}/a\$(touch pwned)\"b/bootgly'"),
            description: 'shell metacharacters in the recorded paths are quoted, not interpreted — got: ' . implode(' | ', $syntax)
         );
         // @@ The wrapper resolves and canonicalizes the script at runtime
         yield assert(
            assertion: str_contains($wrapper, 'SOURCE="$DIR/bootgly"')
               && str_contains($wrapper, 'CANDIDATE="$(canonical "$SOURCE")"')
               && str_contains($wrapper, 'SCRIPT="$CANDIDATE"')
               && str_contains($wrapper, 'BINARY="$(canonical "$BINARY")"')
               && str_ends_with($wrapper, "\n"),
            description: 'the wrapper canonicalizes its binary and resolves the launcher at RUN time from $PWD'
         );

         // @@ install() records the active workspace launcher as its fallback,
         //    not the framework root nested inside a Kit.
         $Install = new ReflectionMethod(SetupCommand::class, 'install');
         $installed = $Install->invoke(
            $Command,
            'bootgly',
            "{$root}/install",
            "{$root}/install/bootgly"
         );
         $installedWrapper = file_get_contents("{$root}/install/bootgly");
         $workingLauncher = escapeshellarg(BOOTGLY_WORKING_DIR . 'bootgly');

         yield assert(
            assertion: $installed === true
               && is_string($installedWrapper)
               && str_contains($installedWrapper, "SCRIPT={$workingLauncher}"),
            description: 'install records the active working launcher as the fallback'
         );
      }
      finally {
         $erase();
      }
   }
);
