<?php

namespace Bootgly\API\Environment;


use function assert;
use function bin2hex;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function random_bytes;
use function rmdir;
use function scandir;
use function str_repeat;
use function sys_get_temp_dir;
use function unlink;
use ReflectionMethod;

use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'It should read the commit from every shape git writes',
   test: function () {
      // ! The git-metadata reader, reachable with an arbitrary installation dir
      $Inspect = new ReflectionMethod(Build::class, 'inspect');
      $inspect = static fn (string $base): null|string => $Inspect->invoke(null, $base);

      // ! Fixture roots — each shape gets its own installation directory
      $base = sys_get_temp_dir() . '/bootgly-build-' . bin2hex(random_bytes(4)) . '/';

      $make = static function (string $path): void {
         mkdir($path, 0777, true);
      };
      $write = static function (string $path, string $content): void {
         file_put_contents($path, $content);
      };

      $commit = str_repeat('ab12cd34', 5);

      // @ No git metadata at all — an unidentifiable source
      $make("{$base}bare/");

      yield assert(
         assertion: $inspect("{$base}bare/") === null,
         description: 'An installation without git metadata reports no commit'
      );

      // @ A detached HEAD holds the commit itself — how submodules are pinned
      $make("{$base}detached/.git/");
      $write("{$base}detached/.git/HEAD", "{$commit}\n");

      yield assert(
         assertion: $inspect("{$base}detached/") === $commit,
         description: 'A detached HEAD yields its commit directly'
      );

      // @ A symbolic HEAD resolves through the loose ref file
      $make("{$base}loose/.git/refs/heads/");
      $write("{$base}loose/.git/HEAD", "ref: refs/heads/main\n");
      $write("{$base}loose/.git/refs/heads/main", "{$commit}\n");

      yield assert(
         assertion: $inspect("{$base}loose/") === $commit,
         description: 'A symbolic HEAD resolves through its loose ref'
      );

      // @ Packed refs — git packs the loose refs away on `gc`
      $make("{$base}packed/.git/");
      $write("{$base}packed/.git/HEAD", "ref: refs/heads/main\n");
      $write(
         "{$base}packed/.git/packed-refs",
         "# pack-refs with: peeled fully-peeled sorted \n"
         . str_repeat('99', 20) . " refs/heads/other\n"
         . "{$commit} refs/heads/main\n"
      );

      yield assert(
         assertion: $inspect("{$base}packed/") === $commit,
         description: 'A packed ref resolves when no loose ref remains'
      );

      // @ A `.git` file points elsewhere — the submodule and worktree shape
      $make("{$base}linked/");
      $make("{$base}modules/Bootgly/");
      $write("{$base}modules/Bootgly/HEAD", "{$commit}\n");
      $write("{$base}linked/.git", "gitdir: ../modules/Bootgly\n");

      yield assert(
         assertion: $inspect("{$base}linked/") === $commit,
         description: 'A gitdir pointer follows through to the real git directory'
      );

      // @ Worktrees write the pointer as an absolute path
      $make("{$base}worktree/");
      $write("{$base}worktree/.git", "gitdir: {$base}modules/Bootgly\n");

      yield assert(
         assertion: $inspect("{$base}worktree/") === $commit,
         description: 'An absolute gitdir pointer resolves the same way'
      );

      // @ Malformed metadata never yields a bogus commit
      $make("{$base}broken/.git/");
      $write("{$base}broken/.git/HEAD", "ref: refs/heads/gone\n");

      $make("{$base}garbage/.git/");
      $write("{$base}garbage/.git/HEAD", "not a commit\n");

      $make("{$base}stray/");
      $write("{$base}stray/.git", "notgitdir: somewhere\n");

      yield assert(
         assertion: $inspect("{$base}broken/") === null
            && $inspect("{$base}garbage/") === null
            && $inspect("{$base}stray/") === null,
         description: 'Unresolvable refs and malformed pointers report no commit'
      );

      // ! Teardown — the fixture tree leaves nothing behind (nested `mkdir`
      //   makes intermediate directories nobody recorded, so it walks)
      $purge = static function (string $path) use (&$purge): void {
         foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
               continue;
            }

            $found = $path . $entry;

            is_dir($found) === true
               ? $purge("{$found}/")
               : unlink($found);
         }

         rmdir($path);
      };

      $purge($base);

      yield assert(
         assertion: is_dir($base) === false,
         description: 'The fixture tree is removed on teardown'
      );
   }
);
