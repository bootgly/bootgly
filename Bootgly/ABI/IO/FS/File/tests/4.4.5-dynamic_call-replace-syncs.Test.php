<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use function Bootgly\ABI\remove_recursively;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


/**
 * What `replace()` syncs and chmods, observed at the library call: a child PHP runs it
 * under an `LD_PRELOAD` spy that logs every `fsync()`/`fdatasync()` with the file its
 * descriptor holds, and every `chmod()` with the name it was given (or ignores it, as a
 * filesystem with fixed modes does). Durable: the temporary, then the directory. Not
 * durable: nothing. No `chmod()` unless a default ACL overrode the umask — then through
 * the descriptor, never the name.
 */
return new Test(
   description: 'replace() syncs the new file and its directory when durable, nothing otherwise, and chmods only through its descriptor',
   test: function () {
      // ?
      if (function_exists('proc_open') === false || is_dir('/proc/self/fd') === false) {
         yield (new Assertion(description: 'Sync spy: needs proc_open and /proc (Linux) to observe the system calls'))->skip();

         return;
      }

      $root = Temporaries::reserve('file-replace-syncs');
      try {
         // ! The spy: every sync logged with what its descriptor names
         $source = <<<'C'
         #define _GNU_SOURCE
         #include <dlfcn.h>
         #include <fcntl.h>
         #include <stdio.h>
         #include <stdlib.h>
         #include <string.h>
         #include <sys/stat.h>
         #include <unistd.h>
         static ssize_t (*real_readlink)(const char *, char *, size_t);
         static void note (const char *call, int fd) {
            const char *log = getenv("FSYNCSPY_LOG");
            if (!log) return;
            char link[64], target[4096];
            snprintf(link, sizeof link, "/proc/self/fd/%d", fd);
            if (!real_readlink) real_readlink = dlsym(RTLD_NEXT, "readlink");
            ssize_t n = real_readlink(link, target, sizeof target - 1);
            if (n < 0) n = 0;
            target[n] = 0;
            int out = open(log, O_WRONLY | O_APPEND | O_CREAT, 0600);
            if (out < 0) return;
            char line[4200];
            int len = snprintf(line, sizeof line, "%s %s\n", call, target);
            if (write(out, line, len) < 0) {}
            close(out);
         }
         static void name (const char *call, const char *path) {
            const char *log = getenv("FSYNCSPY_LOG");
            if (!log) return;
            int out = open(log, O_WRONLY | O_APPEND | O_CREAT, 0600);
            if (out < 0) return;
            char line[4200];
            int len = snprintf(line, sizeof line, "%s %s\n", call, path);
            if (write(out, line, len) < 0) {}
            close(out);
         }
         /* FSYNCSPY_SWAP=<victim>: the first time a descriptor holding a hidden `.tmp` is read
            back, its name is swapped for a link to the victim — `before` or `after` the read */
         ssize_t readlink (const char *path, char *buf, size_t size) {
            static int done = 0;
            if (!real_readlink) real_readlink = dlsym(RTLD_NEXT, "readlink");
            const char *victim = getenv("FSYNCSPY_SWAP");
            const char *when = getenv("FSYNCSPY_SWAPWHEN");
            if (victim && when && !done && strncmp(path, "/proc/self/fd/", 14) == 0) {
               char target[4096];
               ssize_t n = real_readlink(path, target, sizeof target - 1);
               if (n > 5) {
                  target[n] = 0;
                  const char *base = strrchr(target, '/');
                  if (base && base[1] == '.' && strcmp(target + n - 4, ".tmp") == 0) {
                     done = 1;
                     if (strcmp(when, "before") == 0) {
                        unlink(target);
                        if (symlink(victim, target) < 0) {}
                        return real_readlink(path, buf, size);
                     }
                     ssize_t read = real_readlink(path, buf, size);
                     unlink(target);
                     if (symlink(victim, target) < 0) {}
                     return read;
                  }
               }
            }
            return real_readlink(path, buf, size);
         }
         int chmod (const char *path, mode_t mode) { static int (*real)(const char *, mode_t); if (!real) real = dlsym(RTLD_NEXT, "chmod"); name("chmod", path); if (getenv("FSYNCSPY_NOCHMOD")) return 0; return real(path, mode); }
         int fsync (int fd) { static int (*real)(int); if (!real) real = dlsym(RTLD_NEXT, "fsync"); note("fsync", fd); return real(fd); }
         int fdatasync (int fd) { static int (*real)(int); if (!real) real = dlsym(RTLD_NEXT, "fdatasync"); note("fdatasync", fd); return real(fd); }
         C;
         file_put_contents("{$root}/spy.c", $source);
         $Compiler = @proc_open(
            ['cc', '-shared', '-fPIC', '-o', "{$root}/spy.so", "{$root}/spy.c", '-ldl'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', "{$root}/cc.err", 'w']],
            $pipes
         );
         $compiled = is_resource($Compiler) && proc_close($Compiler) === 0 && is_file("{$root}/spy.so");
         if ($compiled === false) {
            $reason = trim((string) @file_get_contents("{$root}/cc.err"));
            yield (new Assertion(description: "Sync spy: no C compiler (cc) built the preload — the syncs are NOT verified here ({$reason})"))->skip();

            return;
         }

         // ! A child PHP under the spy: one call, its log and its answer
         $bootstrap = <<<'PHP'
         <?php
         [, $base, $mode, $target, $permissions] = $argv;
         umask(022);
         spl_autoload_register(static function (string $class) use ($base): void {
            $file = $base . '/' . str_replace('\\', '/', $class) . '.php';
            if (is_file($file)) {
               require $file;
            }
         });
         $answer = match ($mode) {
            'probe'   => (static function () use ($target): string {
               $Handle = fopen($target, 'c');
               return fsync($Handle) ? 'synced' : 'failed';
            })(),
            'durable' => new Bootgly\ABI\IO\FS\File($target)->replace('DATA', (int) $permissions) ? 'replaced' : 'refused',
            default   => new Bootgly\ABI\IO\FS\File($target)->replace('DATA', (int) $permissions, durable: false) ? 'replaced' : 'refused',
         };
         echo $answer;
         PHP;
         file_put_contents("{$root}/child.php", $bootstrap);
         $runs = 0;
         $run = static function (string $mode, string $target, int $permissions = 0600, bool $ignore = false, string $swap = '') use ($root, &$runs): array {
            $runs++;
            $log = "{$root}/run{$runs}.log";
            $Environment = (array) getenv();
            $Environment['LD_PRELOAD'] = "{$root}/spy.so";
            $Environment['FSYNCSPY_LOG'] = $log;
            if ($ignore) {
               $Environment['FSYNCSPY_NOCHMOD'] = '1';
            }
            if ($swap !== '') {
               $Environment['FSYNCSPY_SWAP'] = "{$root}/victim.key";
               $Environment['FSYNCSPY_SWAPWHEN'] = $swap;
            }
            $Child = proc_open(
               [PHP_BINARY, '-n', "{$root}/child.php", BOOTGLY_ROOT_BASE, $mode, $target, (string) $permissions],
               [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
               $Pipes,
               null,
               $Environment
            );
            if (is_resource($Child) === false) {
               return ['answer' => 'unspawned', 'calls' => []];
            }
            $answer = (string) stream_get_contents($Pipes[1]);
            fclose($Pipes[1]);
            proc_close($Child);

            // @@ Only what lands under the reserved root counts
            $calls = [];
            foreach (@file($log, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
               [$call, $named] = explode(' ', $line, 2) + [1 => ''];
               if ($named === $root || str_starts_with($named, "{$root}/") || str_starts_with($named, '/proc/self/fd/')) {
                  $calls[] = [$call, $named];
               }
            }

            return ['answer' => $answer, 'calls' => $calls];
         };

         // ? The spy must see PHP's own fsync() — otherwise an empty log proves nothing
         $Probe = $run('probe', "{$root}/probe.bin");
         if ($Probe['answer'] !== 'synced' || $Probe['calls'] !== [['fsync', "{$root}/probe.bin"]]) {
            yield (new Assertion(description: 'Sync spy: the preload does not interpose PHP\'s fsync() here — the syncs are NOT verified ('
               . json_encode($Probe) . ')'))->skip();

            return;
         }

         // @ Durable — the temporary first, then the directory the entry landed in
         $durable = "{$root}/durable";
         mkdir($durable);
         $Durable = $run('durable', "{$durable}/records.json");
         $calls = $Durable['calls'];
         yield assert(
            assertion: $Durable['answer'] === 'replaced' && count($calls) === 2
               && $calls[0][0] === 'fsync'
               && preg_match('#\A' . preg_quote($durable, '#') . '/\.[0-9a-f]{16}\.tmp\z#', $calls[0][1]) === 1
               && $calls[1] === ['fsync', $durable],
            description: 'Invalid File #1.1 replace(): a durable replace did not sync the new file, then its directory ('
               . json_encode($Durable) . ')!'
         );

         // @ Not durable — no sync at all
         $volatile = "{$root}/volatile";
         mkdir($volatile);
         $Volatile = $run('volatile', "{$volatile}/records.json", 0644);
         yield assert(
            assertion: $Volatile['answer'] === 'replaced' && $Volatile['calls'] === []
               && file_get_contents("{$volatile}/records.json") === 'DATA'
               && ((int) fileperms("{$volatile}/records.json") & 0777) === 0644,
            description: 'Invalid File #2.1 replace(): a non-durable replace synced or chmod-ed something ('
               . json_encode($Volatile) . ')!'
         );
         // # The durable run chmod-ed nothing either: the umask gave the temporary its mode
         yield assert(
            assertion: array_filter($calls, static fn (array $Call): bool => $Call[0] === 'chmod') === [],
            description: 'Invalid File #2.2 replace(): a chmod() ran without a default ACL ('
               . json_encode($calls) . ')!'
         );

         // @ A default ACL overrides the umask — then, and only then, a chmod()
         $shared = "{$root}/shared";
         mkdir($shared);
         $Setfacl = @proc_open(
            ['setfacl', '-d', '-m', 'u::rwx,g::rwx,o::rwx', $shared],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
         );
         if ((is_resource($Setfacl) && proc_close($Setfacl) === 0) === false) {
            yield (new Assertion(description: 'Sync spy, default ACL leg: no setfacl here, or the temporary filesystem refuses ACLs'))->skip();

            return;
         }
         // # Through the descriptor that holds the temporary — never through its name
         $Pinned = $run('volatile', "{$shared}/pinned.json", 0640);
         clearstatcache(true);
         yield assert(
            assertion: $Pinned['answer'] === 'replaced' && count($Pinned['calls']) === 1
               && $Pinned['calls'][0][0] === 'chmod'
               && preg_match('#\A/proc/self/fd/[0-9]+\z#', $Pinned['calls'][0][1]) === 1
               && ((int) fileperms("{$shared}/pinned.json") & 0777) === 0640,
            description: 'Invalid File #3.1 replace(): under a default ACL the mode was not set through the descriptor ('
               . json_encode($Pinned) . ')!'
         );
         // # A filesystem that ignores chmod(): the mode a plain write gives is accepted, no other
         $Plain = $run('volatile', "{$shared}/plain.json", 0644, ignore: true);
         $Strict = $run('volatile', "{$shared}/strict.json", 0600, ignore: true);
         clearstatcache(true);
         yield assert(
            assertion: $Plain['answer'] === 'replaced' && ((int) fileperms("{$shared}/plain.json") & 0777) === 0666,
            description: 'Invalid File #3.2 replace(): a mode fixed by the filesystem refused the mode a plain write gives ('
               . json_encode($Plain) . ')!'
         );
         yield assert(
            assertion: $Strict['answer'] === 'refused' && file_exists("{$shared}/strict.json") === false
               && glob("{$shared}/.*.tmp") === [],
            description: 'Invalid File #3.3 replace(): a mode the filesystem would not give was accepted ('
               . json_encode($Strict) . ')!'
         );

         // @ A name swapped for a link to a victim while the mode is being set: the victim
         //   keeps its mode, nothing lands at the target, and the replace says it failed
         $victim = "{$root}/victim.key";
         file_put_contents($victim, 'SECRET');
         // # Swapped before the descriptor is read back: no descriptor holds that name anymore
         chmod($victim, 0600);
         $Before = $run('volatile', "{$shared}/before.json", 0640, swap: 'before');
         clearstatcache(true);
         $chmods = array_filter($Before['calls'], static fn (array $Call): bool => $Call[0] === 'chmod');
         yield assert(
            assertion: $Before['answer'] === 'refused' && $chmods === []
               && ((int) fileperms($victim) & 0777) === 0600 && file_exists("{$shared}/before.json") === false,
            description: 'Invalid File #4.1 replace(): a name swapped before the mode was set sent a chmod() through it ('
               . json_encode($Before) . ', victim ' . decoct((int) fileperms($victim) & 0777) . ')!'
         );
         // # Swapped right after: the mode goes to the opened file, the swap is still refused
         $After = $run('volatile', "{$shared}/after.json", 0640, swap: 'after');
         clearstatcache(true);
         yield assert(
            assertion: $After['answer'] === 'refused'
               && ((int) fileperms($victim) & 0777) === 0600 && file_get_contents($victim) === 'SECRET'
               && file_exists("{$shared}/after.json") === false && is_link("{$shared}/after.json") === false,
            description: 'Invalid File #4.2 replace(): a name swapped after the mode was set was renamed over the target ('
               . json_encode($After) . ', victim ' . decoct((int) fileperms($victim) & 0777) . ')!'
         );
         yield assert(
            assertion: array_values(array_diff(scandir($shared) ?: [], ['.', '..', 'pinned.json', 'plain.json'])) === [],
            description: 'Invalid File #4.3 replace(): a refused replace left an entry behind ('
               . json_encode(scandir($shared)) . ')!'
         );
      }
      finally {
         remove_recursively($root);
      }
   }
);
