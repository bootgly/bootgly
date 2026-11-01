<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Environment;


use const BOOTGLY_ROOT_DIR;
use const PHP_BINARY;
use function array_diff;
use function array_fill;
use function array_merge;
use function array_values;
use function assert;
use function chmod;
use function clearstatcache;
use function count;
use function fclose;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function fileinode;
use function fileperms;
use function is_array;
use function is_dir;
use function is_file;
use function is_link;
use function is_writable;
use function json_decode;
use function json_encode;
use function mkdir;
use function preg_match;
use function proc_close;
use function proc_open;
use function rmdir;
use function scandir;
use function sprintf;
use function str_contains;
use function str_repeat;
use function stream_get_contents;
use function symlink;
use function unlink;

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


/**
 * M7 (same uid): `Environment::save()` creates its file 0600 beside the
 * destination — even under a default ACL, which overrides the umask — and
 * renames it over the entry, so a link at the destination is replaced, never
 * written through. A path it cannot write (a missing or read-only directory,
 * a stream wrapper) or a write cut short returns `false`, the destination
 * keeps its bytes and no temporary is left anywhere.
 *
 * `save()` dumps the WHOLE environment, so it runs in a child whose
 * environment is synthetic: the runner's own variables (CI tokens) must never
 * be copied into a fixture file or an assertion message. The paths travel in
 * argv — an environment variable would land in the dump itself — and the
 * child is jailed to the fixture (`open_basedir`), so a temporary created
 * anywhere but beside the destination fails here. The fixture shares /tmp's
 * filesystem: without the jail such a temporary would pass every leg, while
 * on a host whose /tmp is another filesystem `rename()` falls back to a copy
 * that writes through the link.
 */
return new Test(
   description: 'M7: Environment::save() writes 0600 through a random sibling and rename() — a link at the destination is replaced, never followed',
   test: function () {
      // ! Fixture
      $dir = Temporaries::reserve('environment-save');
      /**
       * Run save() in a child with a synthetic environment, one call per entry
       * [path, umask, flip, bulk]: [result|Throwable class, umask after the call].
       * `flip` [link, target, warm] first opens `warm` through `link` (PHP
       * caches the resolution), then repoints `link` from another process;
       * `bulk` exports that many bytes more for the call. `$blocks` caps the
       * size of any file the child writes (`ulimit -f`; past it, EFBIG).
       */
      $Save = static function (array $saves, null|int $blocks = null) use ($dir): array {
         $code = <<<'PHP'
         [, $autoboot, $dir, $saves] = $argv;
         require $autoboot;
         error_reporting(E_ALL);
         ini_set('open_basedir', $dir . PATH_SEPARATOR . BOOTGLY_ROOT_DIR);
         $results = [];
         foreach (json_decode($saves, true) as $entry) {
            [$path, $mask, $flip, $bulk] = $entry + [3 => 0];
            if ($flip !== null) {
               [$link, $target, $warm] = $flip;
               fclose(fopen($warm, 'rb'));
               $Flip = proc_open([PHP_BINARY, '-r', 'unlink($argv[1]); symlink($argv[2], $argv[1]);', '--', $link, $target], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $Pipes);
               stream_get_contents($Pipes[1]);
               fclose($Pipes[1]);
               fclose($Pipes[2]);
               proc_close($Flip);
            }
            if ($bulk > 0) {
               putenv('M7_BULK=' . str_repeat('x', $bulk));
            }
            umask($mask);
            try {
               $result = Bootgly\API\Environment::save($path);
            }
            catch (Throwable $Throwable) {
               $result = $Throwable::class;
            }
            putenv('M7_BULK');
            $results[] = [$result, sprintf('%04o', umask())];
         }
         echo json_encode($results);
         PHP;
         $command = [PHP_BINARY, '-r', $code, '--', BOOTGLY_ROOT_DIR . 'autoboot.php', $dir, (string) json_encode($saves)];
         // ! A file size cap: SIGXFSZ ignored, so a write past it fails (EFBIG) instead of killing the child
         if ($blocks !== null) {
            $command = ['sh', '-c', "trap '' XFSZ; ulimit -f {$blocks} && exec \"\$0\" \"\$@\"", ...$command];
         }
         $Process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $Pipes, $dir, [
            'PATH' => '/usr/bin:/bin',
            'HOME' => $dir,
            'LANG' => 'C',
            'DB_PASSWORD' => 'm7-synthetic-secret',
         ]);
         $output = (string) stream_get_contents($Pipes[1]);
         fclose($Pipes[1]);
         fclose($Pipes[2]);
         proc_close($Process);
         $results = json_decode($output, true);
         // ? A child that died prints no JSON: every call reports its output
         $unparsable = array_fill(0, count($saves), ["unparsable: {$output}", null]);

         return is_array($results) ? $results + $unparsable : $unparsable;
      };
      /** Run a shell script with the path as `$1` — never inside the script: [exit status, stdout]. */
      $Shell = static function (string $script, string $path): array {
         $Process = proc_open(['sh', '-c', $script, 'sh', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $Pipes);
         $output = (string) stream_get_contents($Pipes[1]);
         fclose($Pipes[1]);
         fclose($Pipes[2]);

         return [proc_close($Process), $output];
      };
      $Mode = static function (string $file): string {
         clearstatcache();
         return is_link($file) ? 'link' : (is_file($file) ? sprintf('%04o', fileperms($file) & 0o777) : 'absent');
      };
      /** Every temporary left anywhere under $root — scandir(), no pattern built from the path. */
      $Temporaries = static function (string $root) use (&$Temporaries): array {
         $found = [];
         foreach (@scandir($root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
               continue;
            }
            $path = "{$root}/{$entry}";
            if (preg_match('/\.tmp\z/', $entry) === 1) {
               $found[] = $path;
            }
            if (is_dir($path) && is_link($path) === false) {
               $found = array_merge($found, $Temporaries($path));
            }
         }

         return $found;
      };

      try {
         // @ A fresh path
         [[$fresh]] = $Save([["{$dir}/fresh.env", 0o022, null]]);
         yield assert(
            assertion: $fresh === true && $Mode("{$dir}/fresh.env") === '0600',
            description: 'a fresh dump is created 0600 under umask 022 (got ' . json_encode($fresh) . ", mode {$Mode("{$dir}/fresh.env")})"
         );
         yield assert(
            assertion: str_contains((string) @file_get_contents("{$dir}/fresh.env"), 'm7-synthetic-secret'),
            description: 'the dump holds the whole environment, as documented (control)'
         );

         // @ An existing world-writable destination: a new inode, 0600
         file_put_contents("{$dir}/existing.env", "old\n");
         chmod("{$dir}/existing.env", 0o666);
         $inode = fileinode("{$dir}/existing.env");
         [[$existing]] = $Save([["{$dir}/existing.env", 0o022, null]]);
         clearstatcache();
         yield assert(
            assertion: $existing === true && $Mode("{$dir}/existing.env") === '0600' && fileinode("{$dir}/existing.env") !== $inode,
            description: "an existing 0666 destination is replaced by a new 0600 inode (got {$Mode("{$dir}/existing.env")})"
         );

         // @ A link to another file: the link is replaced, its target untouched
         file_put_contents("{$dir}/victim.ini", "keep=me\n");
         chmod("{$dir}/victim.ini", 0o600);
         symlink('victim.ini', "{$dir}/linked.env");
         // @ A dangling link: its target is never created
         symlink('never.env', "{$dir}/dangling.env");
         [[$linked], [$dangling]] = $Save([["{$dir}/linked.env", 0o022, null], ["{$dir}/dangling.env", 0o022, null]]);
         yield assert(
            assertion: file_get_contents("{$dir}/victim.ini") === "keep=me\n",
            description: 'a link at the destination is not written through: its target keeps its bytes'
         );
         yield assert(
            assertion: $linked === true && $Mode("{$dir}/linked.env") === '0600',
            description: "the link itself is replaced by the private file (got {$Mode("{$dir}/linked.env")})"
         );
         yield assert(
            assertion: $dangling === true && file_exists("{$dir}/never.env") === false && $Mode("{$dir}/dangling.env") === '0600',
            description: 'a dangling link does not create its target'
         );

         // @ A valid name near NAME_MAX: the temporary sibling must not derive from it
         $long = str_repeat('n', 240) . '.env';
         [[$longest]] = $Save([["{$dir}/{$long}", 0o022, null]]);
         yield assert(
            assertion: $longest === true && $Mode("{$dir}/{$long}") === '0600',
            description: 'a 244-byte basename (valid) is saved: the temporary name is not built from it'
         );

         // @ The process umask is the caller's again once save() returns — not a default one
         [[$saved, $mask]] = $Save([["{$dir}/mask.env", 0o027, null]]);
         yield assert(
            assertion: $saved === true && $mask === '0027',
            description: "save() restores the caller's umask 0027 it narrowed (got {$mask})"
         );

         // @ A write that cannot land: false, never a throw
         mkdir("{$dir}/adir");
         [[$directory], [$nul], [$missing]] = $Save([
            ["{$dir}/adir", 0o022, null], ["{$dir}/nul\0path", 0o022, null], ["{$dir}/missing/x.env", 0o022, null],
         ]);
         yield assert(
            assertion: $directory === false,
            description: 'a directory at the destination makes save() return false (got ' . json_encode($directory) . ')'
         );
         yield assert(
            assertion: $nul === false,
            description: 'a NUL byte in the path returns false instead of throwing (got ' . json_encode($nul) . ')'
         );
         yield assert(
            assertion: $missing === false && file_exists("{$dir}/missing") === false,
            description: 'a missing parent directory returns false instead of throwing (got ' . json_encode($missing) . ')'
         );

         // @ A write cut short (a 8 KiB file size cap under a 256 KiB dump: EFBIG): the old file stays whole
         file_put_contents("{$dir}/bounded.env", "old\n");
         [[$bounded]] = $Save([["{$dir}/bounded.env", 0o022, null, 262144]], 16);
         $left = $Temporaries($dir);
         yield assert(
            assertion: $bounded === false && @file_get_contents("{$dir}/bounded.env") === "old\n" && $left === [],
            description: 'a write that cannot complete returns false: the destination keeps its bytes, no partial temporary is left (got '
               . json_encode($bounded) . ', destination ' . json_encode(@file_get_contents("{$dir}/bounded.env", length: 16)) . ', left ' . json_encode($left) . ')'
         );

         // @ A stream wrapper cannot rename() or unlink() the temporary it opened: refused before anything exists
         $before = $Temporaries($dir);
         [[$filtered], [$local]] = $Save([
            ["php://filter/write=string.rot13/resource={$dir}/filtered.env", 0o022, null], ["file://{$dir}/local.env", 0o022, null],
         ]);
         $left = array_values(array_diff($Temporaries($dir), $before));
         yield assert(
            assertion: $filtered === false && file_exists("{$dir}/filtered.env") === false && $left === [],
            description: 'a php://filter path returns false and leaves no temporary anywhere in the fixture (got '
               . json_encode($filtered) . ', left ' . json_encode($left) . ')'
         );
         yield assert(
            assertion: $local === true && $Mode("{$dir}/local.env") === '0600',
            description: 'a file:// path is a local path: it is saved 0600 (control; got ' . json_encode($local) . ')'
         );

         // @ The parent resolves through a link a deploy repoints: the temporary is made where rename() looks
         mkdir("{$dir}/release-1");
         mkdir("{$dir}/release-2");
         file_put_contents("{$dir}/release-1/warm", "1\n");
         file_put_contents("{$dir}/release-2/warm", "2\n");
         symlink('release-1', "{$dir}/current");
         [[$flipped]] = $Save([["{$dir}/current/app.env", 0o022, ["{$dir}/current", 'release-2', "{$dir}/current/warm"]]]);
         $stale = $Temporaries("{$dir}/release-1");
         yield assert(
            assertion: $flipped === true && $Mode("{$dir}/release-2/app.env") === '0600' && $stale === [],
            description: 'a parent link repointed by another process after PHP cached it: the dump lands in the new target, no temporary is stranded in the old one (got '
               . json_encode($flipped) . ', stranded ' . json_encode($stale) . ')'
         );

         $left = $Temporaries($dir);
         yield assert(
            assertion: $left === [],
            description: 'no temporary sibling is left behind (left ' . json_encode($left) . ')'
         );

         // ! The legs this runner cannot prove, reported after every leg that ran
         $skipped = [];

         // ? A runner that writes into a 0555 directory (root, CAP_DAC_OVERRIDE) cannot prove the read-only leg
         mkdir("{$dir}/readonly");
         chmod("{$dir}/readonly", 0o555);
         clearstatcache();
         if (is_writable("{$dir}/readonly")) {
            $skipped[] = 'Skipped: this runner writes into a 0555 directory (root), so the read-only leg proves nothing here';
         }
         else {
            [[$readonly]] = $Save([["{$dir}/readonly/x.env", 0o022, null]]);
            yield assert(
               assertion: $readonly === false && (@scandir("{$dir}/readonly") ?: []) === ['.', '..'],
               description: 'a read-only parent directory returns false instead of throwing, and leaves nothing in it (got ' . json_encode($readonly) . ')'
            );
         }

         // ? A default ACL needs setfacl and a filesystem that honours it: without both, nothing to prove
         mkdir("{$dir}/acl");
         [$granted] = $Shell('command -v setfacl >/dev/null 2>&1 && setfacl -d -m u::rw,g::r,o::r "$1"', "{$dir}/acl");
         [$created] = $granted === 0 ? $Shell('umask 077 && : > "$1"', "{$dir}/acl/control.env") : [1];
         if ($created !== 0 || $Mode("{$dir}/acl/control.env") !== '0644') {
            $skipped[] = 'Skipped: no setfacl here, or the filesystem does not apply a default ACL over the umask';
         }
         else {
            [[$shared]] = $Save([["{$dir}/acl/app.env", 0o022, null]]);
            [, $entries] = $Shell('getfacl --omit-header "$1" 2>/dev/null', "{$dir}/acl/app.env");
            yield assert(
               assertion: $shared === true && $Mode("{$dir}/acl/app.env") === '0600'
                  && preg_match('/^other::/m', $entries) === 1
                  && preg_match('/^(?:group|other|mask)::\S*r/m', $entries) === 0
                  && $Temporaries("{$dir}/acl") === [],
               description: 'a default ACL (u::rw,g::r,o::r: a plain create under umask 077 comes out 0644) still gives a 0600 dump no group or other reads (got '
                  . json_encode($shared) . ", mode {$Mode("{$dir}/acl/app.env")}, ACL " . json_encode($entries) . ')'
            );
         }

         // @ Visible skips, last: a skipped Assertion would lend its description to the next leg
         foreach ($skipped as $reason) {
            yield (new Assertion(description: $reason))->skip();
         }
      }
      finally {
         @chmod("{$dir}/readonly", 0o755);
         foreach ($Temporaries($dir) as $temporary) {
            @unlink($temporary);
         }
         foreach ([
            'fresh.env', 'existing.env', 'victim.ini', 'linked.env', 'dangling.env', 'never.env', 'mask.env', 'bounded.env', 'filtered.env',
            'local.env', 'current', 'release-1/warm', 'release-1/app.env', 'release-2/warm', 'release-2/app.env',
            'readonly/x.env', 'acl/control.env', 'acl/app.env',
         ] as $name) {
            @unlink("{$dir}/{$name}");
         }
         @unlink("{$dir}/" . str_repeat('n', 240) . '.env');
         foreach (['adir', 'missing', 'release-1', 'release-2', 'readonly', 'acl'] as $name) {
            @rmdir("{$dir}/{$name}");
         }
         @rmdir($dir);
      }
   }
);
