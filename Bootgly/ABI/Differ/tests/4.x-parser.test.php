<?php

use Bootgly\ABI\Differ\Diff;
use Bootgly\ABI\Differ\Diff\Line;
use Bootgly\ABI\Differ\Parser;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Parser: unified diff → list<Diff>',
   test: function () {
      $Parser = new Parser;

      $patch = <<<'PATCH'
diff --git a/Test.txt b/Test.txt
index abcdefg..abcdefh 100644
--- a/Test.txt
+++ b/Test.txt
@@ -20,4 +20,5 @@ class Foo
     const ONE = 1;
     const TWO = 2;
+    const THREE = 3;
     const FOUR = 4;
PATCH;

      $diffs = $Parser->parse($patch);

      yield assert(
         assertion: count($diffs) === 1 && $diffs[0] instanceof Diff,
         description: 'one Diff parsed'
      );
      yield assert(
         assertion: $diffs[0]->from === 'a/Test.txt' && $diffs[0]->to === 'b/Test.txt',
         description: 'from/to filenames'
      );

      $chunks = $diffs[0]->chunks;
      yield assert(
         assertion: count($chunks) === 1 && $chunks[0]->start === 20 && $chunks[0]->startRange === 4,
         description: 'chunk header parsed'
      );

      $added = 0;
      foreach ($chunks[0]->lines as $Line) {
         if ($Line->type === Line::ADDED) {
            $added++;
         }
      }
      yield assert(
         assertion: $added === 1,
         description: 'one ADDED line'
      );

      // @ Empty input
      yield assert(
         assertion: $Parser->parse('') === [],
         description: 'empty input → empty list'
      );
   }
);
