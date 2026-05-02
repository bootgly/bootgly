<?php

use Bootgly\ABI\Differ\Diff;
use Bootgly\ABI\Differ\Inputs\GitDiff;
use Bootgly\ABI\Differ\Inputs\GitDiff\Hunk;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Inputs\\GitDiff: git diff output → renderable hunks',
   test: function () {
      $Input = new GitDiff;

      $patch = <<<'PATCH'
diff --git a/one.txt b/one.txt
index abcdef1..abcdef2 100644
--- a/one.txt
+++ b/one.txt
@@ -10,3 +10,3 @@
 context
-old
+new
 tail
\ No newline at end of file
diff --git a/two.txt b/two.txt
index abcdef3..abcdef4 100644
--- a/two.txt
+++ b/two.txt
@@ -1,2 +1,3 @@
 alpha
+beta
 gamma
@@ -20,2 +21,2 @@
 old-two
-old-three
+new-three
PATCH;

      $diffs = $Input->parse($patch);

      yield assert(
         assertion: count($diffs) === 2
            && $diffs[0] instanceof Diff
            && $diffs[1] instanceof Diff,
         description: 'parse delegates git diff output to unified-diff model'
      );

      $hunks = $Input->extract($patch);

      yield assert(
         assertion: count($hunks) === 3
            && $hunks[0] instanceof Hunk
            && $hunks[1] instanceof Hunk
            && $hunks[2] instanceof Hunk,
         description: 'multi-file, multi-hunk git diff becomes renderable hunks'
      );

      yield assert(
         assertion: $hunks[0]->fromFile === 'one.txt'
            && $hunks[0]->toFile === 'one.txt'
            && $hunks[0]->fromStart === 10
            && $hunks[0]->toStart === 10,
         description: 'hunk normalizes git side prefixes and preserves line offsets'
      );

      yield assert(
         assertion: $hunks[0]->fromLines === ['context', 'old', 'tail']
            && $hunks[0]->toLines === ['context', 'new', 'tail'],
         description: 'hunk reconstructs old/new line arrays and ignores no-newline metadata'
      );

      yield assert(
         assertion: $hunks[1]->fromLines === ['alpha', 'gamma']
            && $hunks[1]->toLines === ['alpha', 'beta', 'gamma']
            && $hunks[2]->fromStart === 20
            && $hunks[2]->toStart === 21,
         description: 'added lines and later hunk offsets are preserved'
      );

      yield assert(
         assertion: $Input->extract('') === []
            && $Input->extract('diff --git a/file.bin b/file.bin' . "\n" . 'Binary files a/file.bin and b/file.bin differ') === [],
         description: 'empty or non-renderable git diff output returns no hunks'
      );
   }
);
