<?php

use Bootgly\ABI\Differ;
use Bootgly\ABI\Differ\Outputs\Unified;
use Bootgly\ABI\Differ\Outputs\UnifiedStrict;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Outputs: a zero-length hunk side names the preceding line, as git does',
   test: function () {
      // ! Zero-length sides (pure insertion/deletion) must follow the unified
      //   convention — start = the line before the change — or GNU patch applies
      //   the change at the wrong position while exiting 0 (DIFF-3)
      $Differ = new Differ(new UnifiedStrict([
         'fromFile'     => 'a',
         'toFile'       => 'b',
         'contextLines' => 0,
      ]));

      // @ Pure insertion: from-side is empty at the hunk
      $output = $Differ->diff(
         "line1\nline2\nline3\nline4\nline5\nline6\nline7\n",
         "line1\nline2\nline3\nline4\nline5\nINSERTED\nline6\nline7\n",
      );
      yield assert(
         assertion: $output === "--- a\n+++ b\n@@ -5,0 +6 @@\n+INSERTED\n",
         description: "Insertion hunk header must be `@@ -5,0 +6 @@`, got:\n$output"
      );

      // @ Pure deletion: to-side is empty at the hunk
      $output = $Differ->diff(
         "line1\nline2\nline3\nline4\nline5\nline6\n",
         "line1\nline2\nline4\nline5\nline6\n",
      );
      yield assert(
         assertion: $output === "--- a\n+++ b\n@@ -3 +2,0 @@\n-line3\n",
         description: "Deletion hunk header must be `@@ -3 +2,0 @@`, got:\n$output"
      );

      // @ Whole-file addition: the from start floors at 0
      $output = $Differ->diff('', "alpha\nbeta\n");
      yield assert(
         assertion: $output === "--- a\n+++ b\n@@ -0,0 +1,2 @@\n+alpha\n+beta\n",
         description: "Empty-from hunk header must be `@@ -0,0 +1,2 @@`, got:\n$output"
      );

      // @ The numbered Unified output shares the same header contract
      $Numbered = new Differ(new Unified(numbered: true, context: 0));
      $output = $Numbered->diff(
         "line1\nline2\nline3\nline4\nline5\nline6\nline7\n",
         "line1\nline2\nline3\nline4\nline5\nINSERTED\nline6\nline7\n",
      );
      yield assert(
         assertion: $output === "--- Original\n+++ New\n@@ -5,0 +6 @@\n+INSERTED\n",
         description: "Unified(numbered) insertion header must be `@@ -5,0 +6 @@`, got:\n$output"
      );
   }
);
