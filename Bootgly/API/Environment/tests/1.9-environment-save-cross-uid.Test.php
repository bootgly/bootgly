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
use function array_fill;
use function array_values;
use function assert;
use function chmod;
use function clearstatcache;
use function count;
use function fclose;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function fileowner;
use function fileperms;
use function filesize;
use function function_exists;
use function getenv;
use function is_array;
use function is_file;
use function is_link;
use function json_decode;
use function json_encode;
use function lstat;
use function mkdir;
use function posix_getuid;
use function preg_grep;
use function proc_close;
use function proc_open;
use function readlink;
use function rmdir;
use function scandir;
use function shell_exec;
use function sprintf;
use function stream_get_contents;
use function trim;
use function unlink;

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


/**
 * M7 (cross uid): another account plants links in a sticky world-writable
 * directory — to the victim's private file, to its own world-writable file,
 * and a dangling one into the victim's directory. The kernel refuses to follow
 * them for the victim (`fs.protected_symlinks`), but PHP resolves links in
 * userspace before `open()`, so HEAD's `save()` wrote through all three.
 * The fixed `save()` never opens the destination: `rename()` swaps the entry,
 * or fails (EPERM) in a sticky directory the other account owns.
 *
 * The other account is uid 65534 when the runner is root, or
 * `unshare --user --map-auto --setuid 1000` (another host uid, CapEff 0)
 * otherwise. Without either the case is SKIPPED — and that is every CI lane:
 * none runs the suite as root (`.github/scripts/install.sh` refuses a root CI
 * user) and none offers an unprivileged user namespace with a subordinate uid
 * range (the container cells refuse `unshare`, macOS has none). It runs on a
 * developer host with `newuidmap` and an /etc/subuid range, or as root in a
 * container started by hand. The Test API carries no reason for a skipped
 * case, so this paragraph is it. A lane that must have the proof sets
 * `BOOTGLY_REQUIRE_ROOT_LEGS=1`, which turns the skip into a failure.
 */
$root = function_exists('posix_getuid') && posix_getuid() === 0;
$namespaced = $root === false
   && function_exists('proc_open')
   && function_exists('shell_exec')
   && trim((string) shell_exec('command -v unshare 2>/dev/null')) !== ''
   && trim((string) shell_exec('command -v newuidmap 2>/dev/null')) !== ''
   && (int) trim((string) shell_exec('grep -c "^$(id -un):" /etc/subuid 2>/dev/null')) > 0
   && trim((string) shell_exec('unshare --user --map-auto --setuid 1000 true >/dev/null 2>&1 && echo yes')) === 'yes';
$required = getenv('BOOTGLY_REQUIRE_ROOT_LEGS') === '1';

return new Test(
   description: 'M7: Environment::save() never writes through links another account planted in a sticky directory',
   skip: function_exists('proc_open') === false || ($root === false && $namespaced === false && $required === false),
   test: function () use ($root, $namespaced) {
      // ? Neither root nor a user namespace here, and the lane demands the legs
      if ($root === false && $namespaced === false) {
         yield assert(
            assertion: false,
            description: 'cross-uid legs NOT run — neither root nor an unprivileged user namespace (unshare --map-auto, newuidmap, /etc/subuid) — and BOOTGLY_REQUIRE_ROOT_LEGS=1 demands them'
         );

         return;
      }

      // ! Fixture: the other account can traverse it and write only in sticky/
      $dir = Temporaries::reserve('environment-save-cross-uid');
      chmod($dir, 0o711);
      mkdir("{$dir}/victim", 0o700);
      file_put_contents("{$dir}/victim/config.ini", "keep=me\n");
      chmod("{$dir}/victim/config.ini", 0o600);
      mkdir("{$dir}/sticky");
      chmod("{$dir}/sticky", 0o1777);
      /** Run a shell command as the other account, cwd = sticky/ (relative names only). */
      $Other = static function (string $script) use ($dir, $root): string {
         $command = $root
            ? ['setpriv', '--reuid=65534', '--regid=65534', '--clear-groups', '/bin/sh', '-c', $script]
            : ['unshare', '--user', '--map-auto', '--setuid', '1000', '/bin/sh', '-c', $script];
         $Process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $Pipes, "{$dir}/sticky", ['PATH' => '/usr/bin:/bin']);
         $output = (string) stream_get_contents($Pipes[1]);
         fclose($Pipes[1]);
         fclose($Pipes[2]);
         proc_close($Process);

         return trim($output);
      };
      /** Run save() on each path in a child with a synthetic environment, as this runner (paths in argv). */
      $Save = static function (array $paths) use ($dir): array {
         $code = <<<'PHP'
         [, $autoboot, $paths] = $argv;
         require $autoboot;
         error_reporting(E_ALL);
         umask(0022);
         $results = [];
         foreach (json_decode($paths, true) as $path) {
            try {
               $results[] = Bootgly\API\Environment::save($path);
            }
            catch (Throwable $Throwable) {
               $results[] = $Throwable::class;
            }
         }
         echo json_encode($results);
         PHP;
         $command = [PHP_BINARY, '-r', $code, '--', BOOTGLY_ROOT_DIR . 'autoboot.php', (string) json_encode($paths)];
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
         $unparsable = array_fill(0, count($paths), "unparsable: {$output}");

         return is_array($results) ? $results + $unparsable : $unparsable;
      };
      /** The temporaries left in one directory — scandir(), no pattern built from the path. */
      $Temporaries = static function (string $directory): array {
         return array_values(preg_grep('/\.tmp\z/', @scandir($directory) ?: []) ?: []);
      };

      try {
         // @ The other account plants its links — and owns a sticky directory of its own, third/
         $uid = $Other(
            'umask 0; mkdir att && : > att/loot.env && : > att/third.env && chmod 0666 att/loot.env att/third.env'
            . ' && ln -s ../victim/config.ini clobber.env && ln -s att/loot.env exfil.env'
            . ' && ln -s ../victim/created.env dangling.env'
            . ' && mkdir third && chmod 1777 third && ln -s ../att/third.env third/link.env && id -u'
         );
         clearstatcache();
         $owner = @lstat("{$dir}/sticky/clobber.env")['uid'] ?? -1;
         $holder = @lstat("{$dir}/sticky/third")['uid'] ?? -1;
         yield assert(
            assertion: $owner !== -1 && $owner !== posix_getuid() && $holder === $owner && is_link("{$dir}/sticky/third/link.env"),
            description: "the links and third/ belong to another account (link owner {$owner}, third/ owner {$holder}, runner " . posix_getuid() . ", other reports {$uid})"
         );

         // @ Control: the kernel itself refuses the victim a plain open() through them
         if (trim((string) @file_get_contents('/proc/sys/fs/protected_symlinks')) === '1' && $root === false) {
            // ! The path is the shell's `$1`, never part of the script
            $Shell = proc_open(['sh', '-c', 'printf control > "$1"', 'sh', "{$dir}/sticky/clobber.env"], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $Pipes);
            stream_get_contents($Pipes[1]);
            stream_get_contents($Pipes[2]);
            fclose($Pipes[1]);
            fclose($Pipes[2]);
            $status = proc_close($Shell);
            yield assert(
               assertion: $status !== 0 && file_get_contents("{$dir}/victim/config.ini") === "keep=me\n",
               description: 'control: fs.protected_symlinks refuses a shell write through the planted link'
            );
         }

         // @ The victim saves through each planted name
         $results = $Save(["{$dir}/sticky/clobber.env", "{$dir}/sticky/exfil.env", "{$dir}/sticky/dangling.env"]);
         clearstatcache();
         yield assert(
            assertion: file_get_contents("{$dir}/victim/config.ini") === "keep=me\n",
            description: "the victim's private file is not clobbered through the link"
         );
         yield assert(
            assertion: filesize("{$dir}/sticky/att/loot.env") === 0,
            description: "nothing reaches the other account's world-writable file (the exfiltration link)"
         );
         yield assert(
            assertion: file_exists("{$dir}/victim/created.env") === false,
            description: "the dangling link does not create a file in the victim's directory"
         );
         foreach (['clobber', 'exfil', 'dangling'] as $index => $name) {
            $entry = "{$dir}/sticky/{$name}.env";
            $state = is_link($entry) ? 'link' : (is_file($entry) ? sprintf('file %04o uid %d', fileperms($entry) & 0o777, fileowner($entry)) : 'absent');
            $result = $results[$index] ?? null;
            yield assert(
               assertion: ($result === true && $state === sprintf('file 0600 uid %d', posix_getuid()))
                  || ($result === false && $state === 'link'),
               description: "save({$name}.env) => " . json_encode($result) . ", entry now {$state}: true only with our 0600 file in place, false only with the link untouched"
            );
         }
         $left = $Temporaries("{$dir}/sticky");
         yield assert(
            assertion: $left === [],
            description: 'no temporary sibling is left in the sticky directory (left ' . json_encode($left) . ')'
         );

         // ? Root (CAP_FOWNER) may replace an entry in any sticky directory: EPERM is a non-root outcome
         if ($root) {
            yield (new Assertion(description: "Skipped: root (CAP_FOWNER) replaces a link in another account's sticky directory, so the EPERM leg proves nothing here"))->skip();
         }
         else {
            // @ A link in a sticky directory the other account owns: rename() over it fails (EPERM)
            [$third] = $Save(["{$dir}/sticky/third/link.env"]);
            clearstatcache();
            $left = $Temporaries("{$dir}/sticky/third");
            yield assert(
               assertion: $third === false
                  && @readlink("{$dir}/sticky/third/link.env") === '../att/third.env'
                  && filesize("{$dir}/sticky/att/third.env") === 0
                  && $left === [],
               description: "in a sticky directory another account owns, save() through its link returns false: the link stays, its target stays empty, no temporary is left (got "
                  . json_encode($third) . ', left ' . json_encode($left) . ')'
            );
         }
      }
      finally {
         // ! The other account removes what it owns (third/ with whatever landed in it); the runner the rest
         $Other('rm -rf att third; for f in clobber.env exfil.env dangling.env; do [ -L "$f" ] && rm -f "$f"; done; true');
         foreach (['clobber', 'exfil', 'dangling'] as $name) {
            @unlink("{$dir}/sticky/{$name}.env");
         }
         foreach ($Temporaries("{$dir}/sticky") as $temporary) {
            @unlink("{$dir}/sticky/{$temporary}");
         }
         @rmdir("{$dir}/sticky");
         @unlink("{$dir}/victim/config.ini");
         @unlink("{$dir}/victim/created.env");
         @rmdir("{$dir}/victim");
         @rmdir($dir);
      }
   }
);
