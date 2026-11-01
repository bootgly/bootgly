<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Resources\Storage;


use const DIRECTORY_SEPARATOR;
use const PHP_BINARY;
use function assert;
use function clearstatcache;
use function file_put_contents;
use function filemtime;
use function in_array;
use function is_dir;
use function is_file;
use function mkdir;
use function proc_close;
use function proc_open;
use function scan;
use function source;
use function str_contains;
use function str_repeat;
use function symlink;
use function time;
use function touch;
use function unlink;
use function wipe;

use Bootgly\ABI\Resources\Storage\Drivers\Local;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


require_once __DIR__ . '/disk.php';


/**
 * The Local driver decides the jail BEFORE it creates a directory: the deepest directory
 * that already exists on the way to the one asked for must resolve inside the root, so an
 * in-root symlink to an outside directory is refused while nothing exists yet (STORE-4),
 * `make()` no longer answers true for an outside directory (STORE-13), and a target ending
 * in `/` keeps its temp file in the jailed parent (STORE-15). The outside directory's
 * mtime is pinned, so a file created and then removed there would still show.
 *
 * Symlinks that stay inside the root — absolute, relative, chained, nested, and one to
 * the root itself — keep working, as do a root that is a symlink and a root created on
 * demand.
 */
return new Test(
   description: 'Storage(Local): directories are jailed before they are created — nothing is made through an escaping symlink',
   skip: DIRECTORY_SEPARATOR === '\\',   // symlinks are unreliable on Windows
   test: function () {
      $base = Temporaries::reserve('storage-symlink-mkdir');

      try {
         $root = "{$base}/root";
         $outside = "{$base}/outside";
         mkdir("{$root}/a/b", 0775, true);
         mkdir("{$outside}/existing/sub", 0775, true);
         file_put_contents("{$outside}/existing/secret.txt", 'SECRET');
         file_put_contents("{$root}/src.txt", 'SRC');
         symlink($outside, "{$root}/link");                  // escaping, absolute
         symlink('../../../outside', "{$root}/a/b/rel");     // escaping, relative, nested
         symlink("{$outside}/missing", "{$root}/dangle");    // dangling, into the outside dir

         // ! Pin the outside directory: any entry created there — even one removed again —
         //   moves its mtime, and its listing must not change
         $before = scan($outside);
         $past = time() - 1000;
         touch($outside, $past, $past);
         $untouched = static function () use ($outside, $before, $past): bool {
            clearstatcache(true);

            return filemtime($outside) === $past && scan($outside) === $before;
         };

         $Disk = new Local($root);

         // @ The four creating operations, through an escaping link, with a missing tail
         yield assert(
            assertion: $Disk->write('link/created/deep/f.txt', source('x')) === false
               && str_contains($Disk->error, 'path escapes the disk root')
               && $untouched(),
            description: 'write() through an escaping link creates no directory outside the root'
         );
         yield assert(
            assertion: $Disk->copy('src.txt', 'link/c1/c2/f.txt') === false
               && str_contains($Disk->error, 'path escapes the disk root')
               && $untouched(),
            description: 'copy() into an escaping link creates no directory outside the root'
         );
         yield assert(
            assertion: $Disk->move('src.txt', 'link/m1/m2/f.txt') === false
               && is_file("{$root}/src.txt") === true
               && $untouched(),
            description: 'move() into an escaping link creates no directory and keeps the source'
         );
         yield assert(
            assertion: $Disk->make('link/k1/k2') === false
               && str_contains($Disk->error, 'path escapes the disk root')
               && $untouched(),
            description: 'make() through an escaping link creates nothing outside the root'
         );
         yield assert(
            assertion: $Disk->write('a/b/rel/w1/w2/f.txt', source('x')) === false
               && $Disk->write('link\\bs\\f.txt', source('x')) === false
               && $untouched(),
            description: 'a nested relative link and a backslash-separated path are refused the same way'
         );

         // @ make() on a directory that already exists outside the root
         $made = [];
         foreach (['link', 'link/', 'link/existing', 'link/existing/sub', 'link/./existing'] as $path) {
            $made[$path] = $Disk->make($path) === false && str_contains($Disk->error, 'path escapes the disk root');
         }
         yield assert(
            assertion: in_array(false, $made, true) === false
               && $Disk->make('link/existing') === $Disk->check('link/existing'),
            description: 'make() refuses an outside directory reached through a link, as check() does'
         );

         // @ A dangling link: the kernel refuses to create through it, and the reason says so
         $refused = static fn (bool $returned): bool => $returned === false
            && str_contains($Disk->error, 'mkdir failed');
         yield assert(
            assertion: $refused($Disk->make('dangle/x'))
               && $refused($Disk->write('dangle/f.txt', source('x')))
               && $refused($Disk->copy('src.txt', 'dangle/x/f.txt'))
               && $refused($Disk->move('src.txt', 'dangle/x/f.txt'))
               && is_dir("{$outside}/missing") === false
               && $untouched(),
            description: 'a dangling link creates nothing, and each failure is reported as mkdir failed'
         );

         // @ A target ending in `/` keeps its temp file in the jailed parent
         yield assert(
            assertion: $Disk->write('link/', source('PAYLOAD')) === false
               && $Disk->copy('src.txt', 'link/') === false
               && $untouched(),
            description: 'a trailing slash after an escaping link opens no temp file outside the root'
         );

         // @ A failed mkdir() is not reported as an escape
         yield assert(
            assertion: $Disk->write('src.txt/sub/f.txt', source('x')) === false
               && str_contains($Disk->error, 'mkdir failed'),
            description: 'mkdir() under a file is reported as mkdir failed, not as an escape'
         );

         // @ A directory swapped for an escaping link after an earlier check is judged as it is
         //   now — the swap runs in another process: a rename() here would flush this one's
         //   realpath cache. Without a realpath cache (size 0, open_basedir) these legs hold
         //   trivially; with one, only a whole-cache flush in the driver passes all three
         $swap = static function (string $dir) use ($outside): bool {
            $Swap = proc_open(
               [PHP_BINARY, '-n', '-r', 'rename($argv[1], "{$argv[1]}.real"); symlink($argv[2], $argv[1]);', '--', $dir, $outside],
               [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
               $pipes
            );

            return $Swap !== false && proc_close($Swap) === 0;
         };
         $escapes = static fn (bool $returned): bool => $returned === false
            && str_contains($Disk->error, 'path escapes the disk root');
         // ! The parent of the write
         mkdir("{$root}/d");
         $Disk->write('d/x.txt', source('X'));
         $Disk->check('d/x.txt');
         yield assert(
            assertion: $swap("{$root}/d") === true
               && $escapes($Disk->write('d/w.txt', source('W')))
               && $untouched(),
            description: 'a directory swapped for an escaping link after a check is judged as it is now'
         );
         unlink("{$root}/d");
         // ! An ancestor of a missing tail
         mkdir("{$root}/e");
         $Disk->write('e/x.txt', source('X'));
         $Disk->check('e/x.txt');
         yield assert(
            assertion: $swap("{$root}/e") === true
               && $escapes($Disk->write('e/new/w.txt', source('W')))
               && $untouched(),
            description: 'a swapped ancestor of a missing tail is judged as it is now'
         );
         unlink("{$root}/e");
         // ! The target of an in-root link
         mkdir("{$root}/b2");
         symlink("{$root}/b2", "{$root}/a2");
         $Disk->write('a2/x.txt', source('X'));
         $Disk->check('a2/x.txt');
         yield assert(
            assertion: $swap("{$root}/b2") === true
               && $escapes($Disk->write('a2/new/w.txt', source('W')))
               && $untouched(),
            description: 'a swapped target of an in-root link is judged as it is now'
         );
         unlink("{$root}/a2");
         unlink("{$root}/b2");

         // @ A name of PATH_MAX bytes or more is refused before any work on it
         yield assert(
            assertion: $Disk->write(str_repeat('a/', 500000) . 'f.txt', source('x')) === false
               && str_contains($Disk->error, 'path too long')
               && $Disk->make(str_repeat('a/', 500000)) === false
               && str_contains($Disk->error, 'path too long'),
            description: 'a path of PATH_MAX bytes or more is refused as too long'
         );

         // @ Symlinks that stay inside the root keep working
         mkdir("{$root}/real/deep", 0775, true);
         symlink("{$root}/real", "{$root}/abs");
         symlink('real', "{$root}/same");
         symlink("{$root}/abs", "{$root}/chain");
         symlink('../real/deep', "{$root}/a/nested");
         symlink($root, "{$root}/self");
         foreach (['abs', 'same', 'chain', 'a/nested', 'self'] as $link) {
            yield assert(
               assertion: $Disk->make("{$link}/m1/m2") === true
                  && $Disk->write("{$link}/n1/n2/f.txt", source('F')) === true
                  && $Disk->copy("{$link}/n1/n2/f.txt", "{$link}/c1/c2/g.txt") === true
                  && $Disk->move("{$link}/c1/c2/g.txt", "{$link}/v1/h.txt") === true
                  && $Disk->make($link) === true,
               description: "an in-root link ({$link}) still makes, writes, copies and moves through"
            );
         }
         yield assert(
            assertion: $Disk->write('self/top.txt', source('T')) === true
               && $Disk->check('self') === true
               && in_array('self/top.txt', $Disk->list('self'), true) === true,
            description: 'a link to the root is the root: its direct children are reachable too'
         );

         // @ Root shapes: a symlinked root, and roots created on demand
         mkdir("{$base}/realroot", 0775);
         symlink("{$base}/realroot", "{$base}/rootlink");
         $Linked = new Local("{$base}/rootlink");
         yield assert(
            assertion: $Linked->write('x/new/f.txt', source('F')) === true && $Linked->make('x/m') === true,
            description: 'a root that is itself a symlink still works'
         );
         $Top = new Local("{$base}/lazy1/not/yet");
         $Nested = new Local("{$base}/lazy2/not/yet");
         $Made = new Local("{$base}/lazy3/not/yet");
         yield assert(
            assertion: $Top->write('top.txt', source('T')) === true
               && $Nested->write('sub/f.txt', source('T')) === true
               && $Made->make('') === true
               && is_dir("{$base}/lazy3/not/yet") === true,
            description: 'a root that does not exist yet is still created on demand'
         );
         // ? The root's own parent is above the jail: an empty target never creates it
         $Above = new Local("{$base}/above/root");
         yield assert(
            assertion: $Above->write('', source('x')) === false && is_dir("{$base}/above") === false,
            description: 'an empty target creates nothing above a root that does not exist yet'
         );

         yield assert(
            assertion: $untouched(),
            description: 'the outside directory was never written to'
         );
      }
      finally {
         // ! Cleanup — links are unlinked, never followed
         wipe($base);
      }
   }
);
