<?php

use Bootgly\ABI\Differ;
use Bootgly\ABI\Differ\Outputs\Unified;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Outputs\\Unified: README sample foo→bar',
   test: function () {
      $Differ = new Differ(new Unified);

      $output = $Differ->diff('foo', 'bar');
      $expected = "--- Original\n+++ New\n@@ @@\n-foo\n+bar\n";

      yield assert(
         assertion: $output === $expected,
         description: 'foo→bar yields canonical README sample'
      );

      // @ With line numbers
      $Numbered = new Differ(new Unified(numbered: true));
      $output   = $Numbered->diff("a\nb\nc\n", "a\nB\nc\n");
      yield assert(
         assertion: str_contains($output, '@@ -') && str_contains($output, ' +'),
         description: 'numbered hunk header present'
      );
      yield assert(
         assertion: str_contains($output, "-b\n") && str_contains($output, "+B\n"),
         description: 'change rendered'
      );
   }
);
