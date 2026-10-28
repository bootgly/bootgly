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


use const BOOTGLY_STORAGE_DIR;
use const BOOTGLY_WORKING_DIR;
use function array_diff;
use function array_filter;
use function array_values;
use function assert;
use function chgrp;
use function chmod;
use function copy;
use function count;
use function escapeshellarg;
use function exec;
use function explode;
use function file_get_contents;
use function file_put_contents;
use function fileowner;
use function fileperms;
use function function_exists;
use function getmypid;
use function getmyuid;
use function implode;
use function is_dir;
use function is_file;
use function is_string;
use function json_encode;
use function ltrim;
use function mkdir;
use function posix_geteuid;
use function posix_getgroups;
use function rmdir;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
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
      // ! A binary placed straight in the temporary directory, and one under
      //   the checkout's storage (for the user-namespace legs)
      $loose = sys_get_temp_dir() . '/bootgly-wrapper-php-' . getmypid();
      $nested = BOOTGLY_STORAGE_DIR . 'tests/wrapper-' . getmypid();
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

      $erase = function () use ($root, $loose, $nested): void {
         foreach ([
            $loose, "{$nested}/wrapper", "{$nested}/php", $nested,
            "{$root}/fakebin/ran", "{$root}/fakebin/id", "{$root}/fakebin", "{$root}/loose",
            "{$root}/kit/projects/App/bootgly", "{$root}/kit/projects/App", "{$root}/kit/projects",
            "{$root}/kit/bootgly", "{$root}/kit",
            "{$root}/sticky/kit/projects/App", "{$root}/sticky/kit/projects",
            "{$root}/sticky/kit/bootgly", "{$root}/sticky/kit", "{$root}/sticky",
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
         // @@ Each write bit is refused on its own: group-writable (0664),
         //    then other-writable (0646)
         $skipped = [];
         foreach ([0664, 0646] as $mode) {
            chmod("{$root}/kit/projects/App/bootgly", $mode);
            $argv = $run("{$root}/kit/projects/App");
            $skipped[] = ($argv[count($argv) - 1] ?? '') === $expected;
         }
         yield assert(
            assertion: $skipped === [true, true],
            description: 'a group-writable and an other-writable launcher are each skipped — got: '
               . implode(' | ', $argv)
         );
         unlink("{$root}/kit/projects/App/bootgly");

         // @@ Only root's sticky directories are safe ancestors: a caller-owned
         //    1777 one could hold a swapped launcher, so the launcher below it
         //    is untrusted and the recorded fallback runs
         mkdir("{$root}/sticky/kit/projects/App", 0755, true);
         file_put_contents(
            "{$root}/sticky/kit/bootgly",
            "#!/usr/bin/env php\n<?php\ndefine('BOOTGLY_WORKING_BASE', __DIR__);\n"
         );
         chmod("{$root}/sticky", 01777);
         $argv = $run("{$root}/sticky/kit/projects/App");
         yield assert(
            assertion: ($argv[count($argv) - 1] ?? '') === "{$root}/fallback",
            description: 'a launcher below a caller-owned sticky ancestor is skipped — got: '
               . implode(' | ', $argv)
         );

         // @@ A writable directory is refused too: the launcher's own, then
         //    an ancestor, group- and then other-writable — while with neither
         //    writable the nearer launcher wins
         file_put_contents(
            "{$root}/kit/projects/App/bootgly",
            "<?php\ndefine('BOOTGLY_WORKING_BASE', __DIR__);\n"
         );
         chmod("{$root}/kit/projects/App/bootgly", 0644);
         $resolved = [];
         foreach (["{$root}/kit/projects/App", "{$root}/kit/projects"] as $directory) {
            foreach ([0775, 0757] as $mode) {
               chmod($directory, $mode);
               $argv = $run("{$root}/kit/projects/App");
               $resolved[] = ($argv[count($argv) - 1] ?? '') === $expected;
               chmod($directory, 0755);
            }
         }
         $argv = $run("{$root}/kit/projects/App");
         $resolved[] = ($argv[count($argv) - 1] ?? '') === "{$root}/kit/projects/App/bootgly";
         unlink("{$root}/kit/projects/App/bootgly");
         yield assert(
            assertion: $resolved === [true, true, true, true, true],
            description: 'a group- or other-writable launcher directory or ancestor is skipped — got: '
               . json_encode($resolved)
         );

         // @@ The owner is a number read from `ls -n`, never a name looked up
         //    (its code, not its comments)
         $code = implode("\n", array_filter(
            explode("\n", $wrapper),
            static fn (string $line): bool => str_starts_with(ltrim($line), '#') === false
         ));
         yield assert(
            assertion: str_contains($code, 'ls -nd')
               && str_contains($code, ' -user ') === false
               && str_contains($code, ' -uid ') === false,
            description: "the wrapper reads owners as numbers, with no name lookup"
         );

         // @@ The owner field, not the group's: a launcher whose group is
         //    another of the caller's groups still resolves
         $groups = function_exists('posix_getgroups') ? (posix_getgroups() ?: []) : [];
         $group = array_values(array_diff($groups, [0, getmyuid()]))[0] ?? null;
         if ($group !== null && chgrp("{$root}/kit/bootgly", $group)) {
            $argv = $run("{$root}/kit/projects/App");
            yield assert(
               assertion: ($argv[count($argv) - 1] ?? '') === $expected,
               description: "a launcher of the caller's in another group ({$group}) is trusted — got: "
                  . implode(' | ', $argv)
            );
         }
         else {
            yield assert(
               assertion: true,
               description: 'skipped: the group-field leg needs a supplementary group other than root and the UID'
            );
         }

         // @@ A binary straight in a root sticky directory is refused: the
         //    direct parent is never exempt, sticky or not
         $temporary = sys_get_temp_dir();
         if (fileowner($temporary) === 0 && (fileperms($temporary) & 01777) === 01777) {
            copy("{$root}/php", $loose);
            chmod($loose, 0755);
            file_put_contents("{$root}/loose", $Compose->invoke($Command, $loose, "{$root}/fallback"));
            chmod("{$root}/loose", 0755);
            $refusal = [];
            exec(
               'cd ' . escapeshellarg("{$root}/plain") . ' && ' . escapeshellarg("{$root}/loose") . ' 2>&1; echo "status=$?"',
               $refusal
            );
            yield assert(
               assertion: ($refusal[count($refusal) - 1] ?? '') === 'status=126'
                  && str_contains(implode(' ', $refusal), 'untrusted Bootgly PHP binary'),
               description: 'a binary whose direct parent is a sticky directory is refused — got: '
                  . implode(' | ', $refusal)
            );
         }
         else {
            yield assert(
               assertion: true,
               description: 'skipped: the sticky-parent leg needs a root-owned 1777 temporary directory'
            );
         }

         // @@ An `id` that answers no number is refused, never trusted
         mkdir("{$root}/fakebin", 0755);
         file_put_contents("{$root}/fakebin/id", "#!/bin/sh\necho nope\n");
         chmod("{$root}/fakebin/id", 0755);
         $refusal = [];
         exec(
            'cd ' . escapeshellarg("{$root}/plain") . ' && PATH=' . escapeshellarg("{$root}/fakebin") . ':"$PATH" '
               . escapeshellarg("{$root}/wrapper") . ' 2>&1; echo "status=$?"',
            $refusal
         );
         yield assert(
            assertion: ($refusal[count($refusal) - 1] ?? '') === 'status=126'
               && str_contains(implode(' ', $refusal), 'caller UID is unknown'),
            description: 'a caller UID that is not a number is refused — got: ' . implode(' | ', $refusal)
         );

         // @@ User namespaces — named as skipped where they are unavailable
         //    (containers, AppArmor, macOS):
         //    - the real root's directories belong to an unmapped UID there,
         //      so a binary under them is refused even where none of them is
         //      writable (the binary sits in the checkout's storage, so the
         //      walk meets non-writable foreign ancestors, not the sticky
         //      temporary directory);
         //    - mapped to root, the wrapper refuses before running anything,
         //      an EUID forged from the environment included
         $probe = [];
         exec('unshare --user --map-root-user true 2>/dev/null; echo "status=$?"', $probe);
         if (($probe[count($probe) - 1] ?? '') === 'status=0') {
            if (is_dir($nested) === false) {
               mkdir($nested, 0755, true);
            }
            copy("{$root}/php", "{$nested}/php");
            chmod("{$nested}/php", 0755);
            file_put_contents("{$nested}/wrapper", $Compose->invoke($Command, "{$nested}/php", "{$root}/fallback"));
            chmod("{$nested}/wrapper", 0755);
            $foreign = [];
            exec(
               'cd ' . escapeshellarg($nested)
                  . ' && unshare --user --map-current-user ' . escapeshellarg("{$nested}/wrapper")
                  . ' 2>&1; echo "status=$?"',
               $foreign
            );
            $rooted = [];
            exec(
               'cd ' . escapeshellarg("{$root}/plain")
                  . ' && unshare --user --map-root-user ' . escapeshellarg("{$root}/wrapper")
                  . ' 2>&1; echo "status=$?"',
               $rooted
            );
            $forged = [];
            exec(
               'cd ' . escapeshellarg("{$root}/plain")
                  . ' && env EUID=4242 unshare --user --map-root-user ' . escapeshellarg("{$root}/wrapper")
                  . ' 2>&1; echo "status=$?"',
               $forged
            );
            // ! Nothing found through PATH runs as root first: an `id` that
            //   leaves a mark must never have run
            file_put_contents("{$root}/fakebin/id", "#!/bin/sh\ntouch '{$root}/fakebin/ran'\necho 0\n");
            exec(
               'cd ' . escapeshellarg("{$root}/plain") . ' && PATH=' . escapeshellarg("{$root}/fakebin") . ':"$PATH" '
                  . 'unshare --user --map-root-user ' . escapeshellarg("{$root}/wrapper") . ' >/dev/null 2>&1'
            );
            $ran = is_file("{$root}/fakebin/ran");
            yield assert(
               assertion: ($foreign[count($foreign) - 1] ?? '') === 'status=126'
                  && str_contains(implode(' ', $foreign), 'untrusted Bootgly PHP binary'),
               description: "a binary under another UID's ancestors is refused — got: "
                  . implode(' | ', $foreign)
            );
            yield assert(
               assertion: ($rooted[count($rooted) - 1] ?? '') === 'status=126'
                  && str_contains(implode(' ', $rooted), 'as root')
                  && ($forged[count($forged) - 1] ?? '') === 'status=126'
                  && str_contains(implode(' ', $forged), 'as root')
                  && $ran === false,
               description: 'root is refused before any PATH program runs, a forged EUID included — got: '
                  . implode(' | ', $rooted) . ' / ' . implode(' | ', $forged) . ($ran ? ' / id ran as root' : '')
            );
         }
         else {
            yield assert(
               assertion: true,
               description: 'skipped: the foreign-owner and root legs need unprivileged user namespaces'
            );
         }
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
