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
use function array_merge;
use function assert;
use function clearstatcache;
use function count;
use function fclose;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function fileperms;
use function is_array;
use function is_dir;
use function is_file;
use function is_link;
use function json_decode;
use function json_encode;
use function mkdir;
use function preg_match;
use function proc_close;
use function proc_open;
use function rmdir;
use function scandir;
use function sprintf;
use function stream_get_contents;
use function symlink;
use function unlink;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


/**
 * `Environment::save()` writes through `File->replace()`, which names its
 * target through `Path` — and `Path` collapses `..` by name and reads a
 * backslash as a separator. `save()` hands it the entry the kernel names
 * instead: the parent resolved as `rename()` resolves it (a `..` after a link
 * climbs out of the link's target; a relative `..` climbs out of the working
 * directory), the name kept as given. A name `File` would read differently
 * (a trailing separator, a backslash) and a `file://` form that names no
 * local path are refused — never written somewhere else — and a convertible
 * sibling (`app.env.php`) is never taken for the target.
 *
 * Like 1.8, each `save()` runs in a child with a synthetic environment, the
 * paths in argv, jailed to the fixture (`open_basedir`).
 */
return new Test(
   description: 'Environment::save() replaces the entry the kernel names — never a lexically normalized or converted one',
   test: function () {
      // ! Fixture
      $dir = Temporaries::reserve('environment-save-entry');
      /** Run save() in a child, one call per entry [path, working directory]: result|Throwable class. */
      $Save = static function (array $saves) use ($dir): array {
         $code = <<<'PHP'
         [, $autoboot, $dir, $saves] = $argv;
         require $autoboot;
         error_reporting(E_ALL);
         ini_set('open_basedir', $dir . PATH_SEPARATOR . BOOTGLY_ROOT_DIR);
         umask(0022);
         $results = [];
         foreach (json_decode($saves, true) as [$path, $cwd]) {
            chdir($cwd);
            try {
               $results[] = Bootgly\API\Environment::save($path);
            }
            catch (Throwable $Throwable) {
               $results[] = $Throwable::class;
            }
         }
         echo json_encode($results);
         PHP;
         $command = [PHP_BINARY, '-r', $code, '--', BOOTGLY_ROOT_DIR . 'autoboot.php', $dir, (string) json_encode($saves)];
         $Process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $Pipes, $dir, [
            'PATH' => '/usr/bin:/bin',
            'HOME' => $dir,
            'LANG' => 'C',
         ]);
         $output = (string) stream_get_contents($Pipes[1]);
         fclose($Pipes[1]);
         fclose($Pipes[2]);
         proc_close($Process);
         $results = json_decode($output, true);
         // ? A child that died prints no JSON: every call reports its output
         $unparsable = array_fill(0, count($saves), "unparsable: {$output}");

         return is_array($results) ? $results + $unparsable : $unparsable;
      };
      $Mode = static function (string $file): string {
         clearstatcache();
         return is_link($file) ? 'link' : (is_file($file) ? sprintf('%04o', fileperms($file) & 0o777) : (is_dir($file) ? 'dir' : 'absent'));
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
         // ! A link into a deeper tree, a working subdirectory, a convertible sibling, a `back/` directory
         mkdir("{$dir}/deep/inner", 0o700, true);
         symlink("{$dir}/deep/inner", "{$dir}/link");
         mkdir("{$dir}/sub");
         mkdir("{$dir}/back");
         file_put_contents("{$dir}/app.env.php", "bait\n");

         // @
         [$linked, $relative, $converted, $trailing, $wrapped, $local, $backslash] = $Save([
            ["{$dir}/link/../climbed.env", $dir],
            ['../relative.env', "{$dir}/sub"],
            ["{$dir}/app.env", $dir],
            ["{$dir}/newdir/", $dir],
            ['file://wrapped.env', $dir],
            ["file://localhost{$dir}/localhost.env", $dir],
            ["{$dir}/back\\slash.env", $dir],
         ]);

         yield assert(
            assertion: $linked === true && $Mode("{$dir}/deep/climbed.env") === '0600' && $Mode("{$dir}/climbed.env") === 'absent',
            description: 'a `..` after a link climbs out of the link\'s target, as rename() does — not out of the link\'s name (got '
               . json_encode($linked) . ", deep/ {$Mode("{$dir}/deep/climbed.env")}, top {$Mode("{$dir}/climbed.env")})"
         );
         yield assert(
            assertion: $relative === true && $Mode("{$dir}/relative.env") === '0600' && $Mode("{$dir}/sub/relative.env") === 'absent',
            description: 'a relative `../name` lands beside the working directory, not inside it (got '
               . json_encode($relative) . ", parent {$Mode("{$dir}/relative.env")}, sub/ {$Mode("{$dir}/sub/relative.env")})"
         );
         yield assert(
            assertion: $converted === true && $Mode("{$dir}/app.env") === '0600' && file_get_contents("{$dir}/app.env.php") === "bait\n",
            description: 'the target is the name given: an `app.env.php` beside it is never taken for it, nor touched (got '
               . json_encode($converted) . ", app.env {$Mode("{$dir}/app.env")})"
         );
         yield assert(
            assertion: $trailing === false && $Mode("{$dir}/newdir") === 'absent',
            description: 'a trailing separator names no file: false, and no `newdir` file is created from its last component (got '
               . json_encode($trailing) . ", newdir {$Mode("{$dir}/newdir")})"
         );
         yield assert(
            assertion: $wrapped === false && $Mode("{$dir}/wrapped.env") === 'absent',
            description: 'a `file://` form without an absolute path is refused, never read as a relative path (got '
               . json_encode($wrapped) . ", wrapped.env {$Mode("{$dir}/wrapped.env")})"
         );
         yield assert(
            assertion: $local === false && $Mode("{$dir}/localhost.env") === 'absent',
            description: 'a `file://localhost/` form is refused (got ' . json_encode($local) . ')'
         );
         yield assert(
            assertion: $backslash === false && $Mode("{$dir}/back/slash.env") === 'absent' && file_exists("{$dir}/back\\slash.env") === false,
            description: 'a backslash in the name is refused: never written as `back/slash.env` (got '
               . json_encode($backslash) . ", back/slash.env {$Mode("{$dir}/back/slash.env")})"
         );

         $left = $Temporaries($dir);
         yield assert(
            assertion: $left === [],
            description: 'no temporary sibling is left anywhere in the fixture (left ' . json_encode($left) . ')'
         );
      }
      finally {
         foreach ($Temporaries($dir) as $temporary) {
            @unlink($temporary);
         }
         foreach ([
            'deep/climbed.env', 'climbed.env', 'relative.env', 'sub/relative.env', 'app.env', 'app.env.php', 'newdir',
            'wrapped.env', 'localhost.env', 'back/slash.env', 'back\\slash.env', 'link',
         ] as $name) {
            @unlink("{$dir}/{$name}");
         }
         foreach (['deep/inner', 'deep', 'sub', 'back', 'newdir'] as $name) {
            @rmdir("{$dir}/{$name}");
         }
         @rmdir($dir);
      }
   }
);
