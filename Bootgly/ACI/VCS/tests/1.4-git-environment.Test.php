<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\VCS;


use const PREG_SET_ORDER;
use const STDIN;
use function array_diff;
use function assert;
use function bin2hex;
use function chmod;
use function count;
use function file_get_contents;
use function file_put_contents;
use function getenv;
use function getmypid;
use function is_dir;
use function is_file;
use function is_link;
use function mkdir;
use function octdec;
use function preg_match;
use function preg_match_all;
use function putenv;
use function random_bytes;
use function readlink;
use function rmdir;
use function scandir;
use function str_contains;
use function stream_get_meta_data;
use function stream_set_blocking;
use function substr_count;
use function sys_get_temp_dir;
use function unlink;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\VCS;


/**
 * What every git child is started with — proven through a fake `git` binary
 * that records its arguments, its stdin and its environment: the network
 * deadline on every transfer (`fetch`, `ls-remote`, `submodule update`), a
 * stdin of its own on `/dev/null` (never the runner's: a prompt or an
 * `fd::0` transport would wait on it), the prompts disabled, one language,
 * the `GIT_DIR` family scrubbed and the user's own configuration channel kept.
 *
 * When the runner's own stdin is `/dev/null` too, a path cannot tell the two
 * apart: the runner's stdin is then marked non-blocking for the run — a flag
 * of the open file itself, so a child handed that same file carries it, and
 * a child given its own `/dev/null` cannot.
 */

return new Test(
   description: 'every git child runs under the deadline, on its own /dev/null, without prompts, with the location scrubbed and the configuration kept',
   test: function () {
      $temp = sys_get_temp_dir();
      $pid = getmypid();
      $nonce = bin2hex(random_bytes(4));
      $base = "{$temp}/bootgly-vcs-env-{$pid}-{$nonce}";
      mkdir($base, 0775, true);
      $Erase = function (string $target) use (&$Erase): void {
         if (is_link($target) === true || is_file($target) === true) {
            unlink($target);

            return;
         }
         if (is_dir($target) === false) {
            return;
         }
         foreach (array_diff((array) scandir($target), ['.', '..']) as $entry) {
            $Erase("{$target}/{$entry}");
         }
         rmdir($target);
      };
      // ! Export variables (null unsets one) and hand back what they replaced — itself the restore
      $Export = static function (array $variables): array {
         $previous = [];
         foreach ($variables as $name => $value) {
            $current = getenv($name);
            $previous[$name] = $current === false ? null : $current;
            putenv($value === null ? $name : "{$name}={$value}");
         }

         return $previous;
      };
      // ! The `O_NONBLOCK` bit of a `/proc/<pid>/fdinfo/<fd>` flags field (octal) — -1 when unreadable
      $Nonblock = static fn (string $flags): int => preg_match('/^[0-7]+$/', $flags) === 1
         ? ((int) octdec($flags) & 0o4000)
         : -1;

      try {
         // ! A `git` that writes its argv, its stdin (the file, the device, the open flags) and its
         //   environment next to itself, and says nothing else — nothing interpolated into the script
         $log = "{$base}/calls.log";
         $fake = "{$base}/git";
         file_put_contents($fake, <<<'SH'
            #!/bin/sh
            log="$(dirname "$0")/calls.log"
            printf 'ARGS:%s\n' "$*" >> "$log"
            printf 'STDIN:%s %s %s\n' "$(readlink /proc/self/fd/0)" "$(stat -L -c %t:%T /proc/self/fd/0)" \
               "$(sed -n 's/^flags:[[:space:]]*//p' /proc/self/fdinfo/0)" >> "$log"
            env | sort >> "$log"
            printf 'END\n' >> "$log"
            exit 0

            SH);
         chmod($fake, 0755);
         mkdir("{$base}/tree", 0775, true);

         $previous = $Export([
            'GIT_DIR' => "{$base}/elsewhere",
            'GIT_CONFIG_GLOBAL' => "{$base}/mine.gitconfig",
            'GIT_ASKPASS' => '/usr/bin/some-dialog',
            'SSH_ASKPASS' => '/usr/bin/some-dialog',
         ]);
         // ! A runner on `/dev/null` marks its stdin non-blocking — restored below, before any yield
         $blocked = stream_get_meta_data(STDIN)['blocked'];
         $marked = readlink('/proc/self/fd/0') === '/dev/null';
         try {
            if ($marked === true) {
               stream_set_blocking(STDIN, false);
            }
            preg_match('/^flags:\s*([0-7]+)$/m', (string) file_get_contents('/proc/self/fdinfo/0'), $runner);
            $armed = $marked === false || $Nonblock($runner[1] ?? '') > 0;

            $VCS = new VCS("{$base}/tree", $fake);
            $VCS->Git->fetch('origin');
            $VCS->Tags->probe('origin');
            $VCS->Submodules->update();
         }
         finally {
            if ($marked === true) {
               stream_set_blocking(STDIN, $blocked);
            }
            $Export($previous);
         }
         $calls = (string) file_get_contents($log);

         yield assert(
            assertion: str_contains($calls, 'ARGS:-c http.lowSpeedLimit=1000 -c http.lowSpeedTime=20 fetch --no-recurse-submodules -- origin +refs/tags/*:refs/tags/*')
               && str_contains($calls, 'ARGS:-c http.lowSpeedLimit=1000 -c http.lowSpeedTime=20 ls-remote --tags --refs -- origin')
               && str_contains($calls, 'ARGS:-c http.lowSpeedLimit=1000 -c http.lowSpeedTime=20 submodule update'),
            description: 'fetch, ls-remote and submodule update all run under the low-speed deadline'
         );

         // @@ One stdin record per child, right under its argv — `/dev/null` (char 1:3), and NOT the
         //   runner's open file (an environment value may span lines: records are read by anchor)
         preg_match_all('/^ARGS:[^\n]*\nSTDIN:([^ \n]*) ([^ \n]*) ([^ \n]*)$/m', $calls, $records, PREG_SET_ORDER);
         $owned = 0;
         foreach ($records as $stdin) {
            if ($stdin[1] === '/dev/null' && $stdin[2] === '1:3' && $Nonblock($stdin[3]) === 0) {
               $owned++;
            }
         }
         yield assert(
            assertion: $armed === true && count($records) === 3 && $owned === 3
               && substr_count($calls, 'ARGS:') === 3 && substr_count($calls, "\nSTDIN:") === 3,
            description: 'every git child reads a /dev/null of its own — never the runner\'s stdin, where a prompt or an fd:: transport would wait on a terminal'
         );

         yield assert(
            assertion: str_contains($calls, "\nGIT_TERMINAL_PROMPT=0\n") && str_contains($calls, "\nGIT_ASKPASS=") === false
               && str_contains($calls, "\nSSH_ASKPASS=") === false && str_contains($calls, "\nSSH_ASKPASS_REQUIRE=never\n")
               && str_contains($calls, "\nLC_ALL=C\n"),
            description: 'no prompt can hang a headless run — the terminal prompt is off, the git and ssh askpass helpers leave the environment, ssh is told never to ask — and messages come in one language'
         );
         yield assert(
            assertion: str_contains($calls, "\nGIT_DIR=") === false && str_contains($calls, "\nGIT_CONFIG_GLOBAL={$base}/mine.gitconfig\n"),
            description: 'the location family (GIT_DIR) is scrubbed while the user\'s configuration channel (GIT_CONFIG_GLOBAL) reaches the child'
         );
      }
      finally {
         $Erase($base);
      }
   }
);
