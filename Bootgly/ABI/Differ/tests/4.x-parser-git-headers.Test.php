<?php

use Bootgly\ABI\Differ\Diff\Line;
use Bootgly\ABI\Differ\Parser;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Parser: git extended headers never leak into another file\'s chunks',
   test: function () {
      $Parser = new Parser;

      // ! A text file followed by a binary file and a rename — every extended
      //   header after the text hunk must be skipped, not fed to it (DIFF-2)
      $patch = <<<'PATCH'
      diff --git a/added.txt b/added.txt
      new file mode 100644
      index 0000000..e69de29
      --- /dev/null
      +++ b/added.txt
      @@ -0,0 +1,2 @@
      +first
      +second
      diff --git a/blob.bin b/blob.bin
      new file mode 100644
      index 0000000..abcdef0
      Binary files /dev/null and b/blob.bin differ
      diff --git a/old-name.txt b/new-name.txt
      similarity index 100%
      rename from old-name.txt
      rename to new-name.txt
      PATCH;

      $Diffs = $Parser->parse($patch);
      $Lines = $Diffs === [] ? [] : $Diffs[0]->chunks[0]->lines;

      yield assert(
         assertion: count($Diffs) === 1 && count($Lines) === 2,
         description: 'The 2-line hunk carries exactly 2 lines, no trailing metadata'
      );
      yield assert(
         assertion: $Lines[0]->type === Line::ADDED && $Lines[0]->content === 'first'
            && $Lines[1]->type === Line::ADDED && $Lines[1]->content === 'second',
         description: 'Both hunk lines are the real insertions'
      );

      // ! The reverse order: a binary section before a text file — its headers
      //   must not seed the next file's chunk either
      $patch = <<<'PATCH'
      diff --git a/blob.bin b/blob.bin
      new file mode 100644
      index 0000000..abcdef0
      Binary files /dev/null and b/blob.bin differ
      diff --git a/one.txt b/one.txt
      index abcdef1..abcdef2 100644
      --- a/one.txt
      +++ b/one.txt
      @@ -1,2 +1,2 @@
       context
      -old
      +new
      PATCH;

      $Diffs = $Parser->parse($patch);
      $contents = [];
      foreach ($Diffs as $Diff) {
         foreach ($Diff->chunks as $Chunk) {
            foreach ($Chunk->lines as $Line) {
               $contents[] = $Line->content;
            }
         }
      }

      yield assert(
         assertion: count($Diffs) === 1 && $contents === ['context', 'old', 'new'],
         description: 'A text hunk after a binary section starts clean'
      );
   }
);
