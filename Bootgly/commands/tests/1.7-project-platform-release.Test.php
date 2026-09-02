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


use function array_diff;
use function assert;
use function bin2hex;
use function escapeshellarg;
use function exec;
use function file_put_contents;
use function getmypid;
use function is_dir;
use function is_file;
use function is_link;
use function mkdir;
use function random_bytes;
use function rmdir;
use function scandir;
use function sys_get_temp_dir;
use function unlink;
use ReflectionMethod;

use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'It should land a platform submodule on the newest release reachable from its pin',
   test: function () {
      // ! `align()` corrects a kit whose gitlink points at an untagged commit.
      //   v1.0.0-beta.3 shipped exactly that: the kit pinned Bootgly at
      //   `chore: open 1.0.0-beta.4 development`, so a fresh install reported
      //   BOOTGLY_VERSION 1.0.0-beta.4-dev — a build that was never released.
      $Align = new ReflectionMethod(ProjectsCommand::class, 'align');
      $Command = new ProjectsCommand;

      $base = sys_get_temp_dir() . '/bootgly-platform-release-' . getmypid()
         . '-' . bin2hex(random_bytes(4));
      mkdir($base, 0775, true);

      // ! Pinned identity: the fixtures must not depend on the machine's git
      //   config, and a signing key would make the fixture commits interactive
      $G = '-c user.name=Bootgly -c user.email=tests@bootgly.local -c commit.gpgsign=false';

      $erase = function (string $target) use (&$erase): void {
         if (is_link($target) === true || is_file($target) === true) {
            unlink($target);

            return;
         }
         if (is_dir($target) === false) {
            return;
         }
         foreach (array_diff((array) scandir($target), ['.', '..']) as $entry) {
            $erase("{$target}/{$entry}");
         }
         rmdir($target);
      };

      /**
       * Build a superproject pinning `Sub` at a six-commit history's tip.
       *
       * @param array<string, int> $tags Tag name => 1-based commit to tag.
       */
      $build = function (string $name, array $tags, string $side = '')
         use ($base, $G): string
      {
         $sub = "{$base}/{$name}.sub";
         $par = "{$base}/{$name}.par";
         mkdir($sub, 0775, true);
         mkdir($par, 0775, true);
         $s = escapeshellarg($sub);
         $p = escapeshellarg($par);

         exec("git -C {$s} init --quiet");
         $shas = [];
         for ($commit = 1; $commit <= 6; $commit++) {
            file_put_contents("{$sub}/f.txt", "c{$commit}\n");
            // ! `g.txt` never changes, so it is identical in every tag's tree.
            //   A change to it is one `git checkout` would happily carry over —
            //   which is what makes the dirty guards load-bearing instead of
            //   merely re-testing git's own refusal to clobber `f.txt`.
            file_put_contents("{$sub}/g.txt", "constant\n");
            exec("git -C {$s} add f.txt g.txt");
            exec("git -C {$s} {$G} commit --quiet -m c{$commit}");
            $shas[$commit] = exec("git -C {$s} rev-parse HEAD");
         }
         // ! Annotated, as every real Bootgly release tag is — `--merged`,
         //   `rev-parse ^{commit}` and `rev-list` all have to peel them
         foreach ($tags as $tag => $at) {
            exec("git -C {$s} {$G} tag -a " . escapeshellarg($tag)
               . ' -m ' . escapeshellarg($tag) . " {$shas[$at]}");
         }

         // ! A tag on a commit the pin cannot reach. Every tag has to exist
         //   BEFORE `submodule add`, because the superproject gets a clone
         if ($side !== '') {
            $branch = exec("git -C {$s} rev-parse --abbrev-ref HEAD");
            exec("git -C {$s} checkout --quiet -b side {$shas[3]}");
            exec("git -C {$s} {$G} commit --quiet --allow-empty -m side");
            exec("git -C {$s} tag " . escapeshellarg($side));
            exec("git -C {$s} checkout --quiet " . escapeshellarg($branch));
         }

         exec("git -C {$p} init --quiet");
         exec("git -C {$p} {$G} commit --quiet --allow-empty -m base");
         exec("git -C {$p} {$G} -c protocol.file.allow=always submodule add --quiet {$s} Sub");
         exec("git -C {$p} {$G} commit --quiet -m add");

         return "{$par}/";
      };

      try {
         // # A pin past a release walks back to it
         $working = $build('past', ['v1.0.0-beta.3' => 5]);

         yield assert(
            assertion: $Align->invoke($Command, $working, 'Sub') === 'v1.0.0-beta.3',
            description: 'a pin one commit past a pre-release lands on the pre-release'
         );

         // # A stable release outranks pre-releases that sort above it
         //   `--sort=-v:refname` ranks `v1.0.0` BELOW `v1.0.0-beta.1`, so a
         //   single-pass implementation passes every other case here and still
         //   hands back `v1.0.0-rc.1` the day 1.0.0 ships.
         $working = $build('stable', [
            'v1.0.0-beta.1' => 2,
            'v1.0.0-rc.1' => 3,
            'v1.0.0' => 4,
         ]);

         yield assert(
            assertion: $Align->invoke($Command, $working, 'Sub') === 'v1.0.0',
            description: 'a stable release beats the pre-releases that sort above it'
         );

         // # Pre-releases compare numerically, not as text
         $working = $build('tenth', ['v1.0.0-beta.9' => 3, 'v1.0.0-beta.10' => 4]);

         yield assert(
            assertion: $Align->invoke($Command, $working, 'Sub') === 'v1.0.0-beta.10',
            description: 'beta.10 outranks beta.9'
         );

         // # A stable release on an OLDER line must not win
         //   "Prefer any stable over any pre-release" reads like the safe rule
         //   and is not: a kit pinned at v1.1.0-beta.1 would be dragged back to
         //   v1.0.0, a whole minor line, and a pin already sitting exactly on a
         //   release would be moved — with a message that looks correct.
         $working = $build('line', [
            'v1.0.0-beta.4' => 2,
            'v1.0.0' => 3,
            'v1.1.0-beta.1' => 6,
         ]);
         $aligned = $Align->invoke($Command, $working, 'Sub');
         $status = exec('git -C ' . escapeshellarg($working) . ' status --short');

         yield assert(
            assertion: $aligned === null && $status === '',
            description: 'a pin on v1.1.0-beta.1 is not dragged back to an older v1.0.0'
         );

         $working = $build('newer', [
            'v1.0.0-beta.4' => 2,
            'v1.0.0' => 3,
            'v1.1.0-beta.2' => 5,
         ]);

         yield assert(
            assertion: $Align->invoke($Command, $working, 'Sub') === 'v1.1.0-beta.2',
            description: 'a pin past v1.1.0-beta.2 lands there, not on the older stable'
         );

         // # `column.ui = always` must not silently disable the correction
         //   git pads `tag` output into columns even on a pipe, and no anchored
         //   pattern matches it — the install would ship the unreleased commit
         //   with no output at all.
         $working = $build('column', ['v1.0.0-beta.3' => 5]);
         exec('git -C ' . escapeshellarg("{$working}Sub") . ' config column.ui always');

         yield assert(
            assertion: $Align->invoke($Command, $working, 'Sub') === 'v1.0.0-beta.3',
            description: 'a column.ui=always config does not defeat the resolution'
         );

         // # A branch sharing the tag's name must never be checked out
         //   `rev-parse` prefers the tag, `checkout` prefers the branch — so the
         //   guard would validate one ref and land on another, ahead of the pin.
         $working = $build('ambiguous', ['v1.0.0-beta.3' => 4]);
         $sub = escapeshellarg("{$working}Sub");
         exec("git -C {$sub} branch v1.0.0-beta.3 HEAD");
         $aligned = $Align->invoke($Command, $working, 'Sub');
         $landed = exec("git -C {$sub} rev-parse HEAD");
         $tagged = exec("git -C {$sub} rev-parse 'refs/tags/v1.0.0-beta.3^{commit}'");

         yield assert(
            assertion: $aligned === 'v1.0.0-beta.3' && $landed === $tagged,
            description: 'the tag wins over a branch of the same name'
         );

         // # A gitlink bump staged in the kit means the pin is mid-edit
         //   This is the release workflow itself: `git update-index --cacheinfo`
         //   stages a new gitlink without touching the checkout. The submodule
         //   still matches the HEAD tree, so comparing only against that would
         //   green-light a correction against a pin that is being replaced.
         $working = $build('bumping', ['v0.9.0' => 2, 'v1.0.0-beta.3' => 5]);
         $sub = escapeshellarg("{$working}Sub");
         $earlier = exec("git -C {$sub} rev-parse HEAD~2");
         exec('git -C ' . escapeshellarg($working)
            . " update-index --cacheinfo 160000,{$earlier},Sub");

         yield assert(
            assertion: $Align->invoke($Command, $working, 'Sub') === null,
            description: 'a kit with a staged gitlink bump is left alone'
         );

         // # A tag the pin cannot reach is never selected
         //   The correction may only ever walk BACKWARDS: pulling a platform
         //   the kit was never built against is worse than an old pin.
         $working = $build('side', ['v1.0.0-beta.3' => 5], 'v9.9.9');

         yield assert(
            assertion: $Align->invoke($Command, $working, 'Sub') === 'v1.0.0-beta.3',
            description: 'a higher tag off the pin\'s history is not selected'
         );

         // # Already on a release: silent no-op, and the kit stays clean
         $working = $build('exact', ['v1.0.0-beta.3' => 6]);
         $aligned = $Align->invoke($Command, $working, 'Sub');
         $status = exec('git -C ' . escapeshellarg($working) . ' status --short');

         yield assert(
            assertion: $aligned === null && $status === '',
            description: 'a pin already on its release moves nothing and dirties nothing'
         );

         // # Nothing released to land on
         $working = $build('untagged', []);

         yield assert(
            assertion: $Align->invoke($Command, $working, 'Sub') === null,
            description: 'a submodule with no tags keeps its pin'
         );

         // # Work in progress belongs to whoever started it
         //   `g.txt` is identical in the release's tree, so git would carry the
         //   edit across happily — only the guard stops this.
         $working = $build('dirty', ['v1.0.0-beta.3' => 5]);
         file_put_contents("{$working}Sub/g.txt", "edited\n");

         yield assert(
            assertion: $Align->invoke($Command, $working, 'Sub') === null,
            description: 'a modified submodule is left alone'
         );

         // # ...including a change that is only staged
         $working = $build('staged', ['v1.0.0-beta.3' => 5]);
         $sub = escapeshellarg("{$working}Sub");
         file_put_contents("{$working}Sub/g.txt", "staged\n");
         exec("git -C {$sub} add g.txt");

         yield assert(
            assertion: $Align->invoke($Command, $working, 'Sub') === null,
            description: 'a submodule with a staged-only change is left alone'
         );

         // # ...and an untracked file the release's tree would overwrite
         $working = $build('untracked', ['v1.0.0-beta.3' => 5]);
         file_put_contents("{$working}Sub/new.txt", "mine\n");

         yield assert(
            assertion: $Align->invoke($Command, $working, 'Sub') === null,
            description: 'a submodule holding an untracked file is left alone'
         );

         // # A submodule the user moved is theirs, not the installer's
         //   The moved-to commit must still have a reachable tag, or this
         //   passes through the no-tags path and proves nothing about the pin.
         $working = $build('moved', ['v0.9.0' => 2, 'v1.0.0-beta.3' => 5]);
         $sub = escapeshellarg("{$working}Sub");
         exec("git -C {$sub} checkout --quiet HEAD~2");

         yield assert(
            assertion: $Align->invoke($Command, $working, 'Sub') === null,
            description: 'a submodule checked out away from its pin is left alone'
         );

         // # ...and moving it is exactly how someone records a deliberate pin
         //   Index and submodule HEAD agree here, so only comparing the two
         //   would green-light overwriting the commit the user chose.
         $working = $build('deliberate', ['v0.9.0' => 2, 'v1.0.0-beta.3' => 5]);
         $sub = escapeshellarg("{$working}Sub");
         exec("git -C {$sub} checkout --quiet HEAD~2");
         exec('git -C ' . escapeshellarg($working) . ' add Sub');

         yield assert(
            assertion: $Align->invoke($Command, $working, 'Sub') === null,
            description: 'a moved submodule the user staged is left alone'
         );
      }
      finally {
         $erase($base);
      }
   }
);
