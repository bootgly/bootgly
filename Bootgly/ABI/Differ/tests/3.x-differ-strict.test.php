<?php

use Bootgly\ABI\Differ;
use Bootgly\ABI\Differ\Exceptions\Configuration;
use Bootgly\ABI\Differ\Outputs\UnifiedStrict;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Outputs\\UnifiedStrict: hunked patch-compatible output',
   test: function () {
      $Differ = new Differ(new UnifiedStrict([
         'fromFile' => 'input.txt',
         'toFile'   => 'output.txt',
      ]));

      // @ Sample from README
      $output = $Differ->diff(
         "1\n2\n3\n4\n5\n6\n",
         "1\n2\n3\nX\n5\n6\n",
      );
      $expected = "--- input.txt\n+++ output.txt\n@@ -1,6 +1,6 @@\n 1\n 2\n 3\n-4\n+X\n 5\n 6\n";

      yield assert(
         assertion: $output === $expected,
         description: 'strict unified diff matches expected sample'
      );

      // @ Identical → empty
      $output = $Differ->diff("a\nb\n", "a\nb\n");
      yield assert(
         assertion: $output === '',
         description: 'identical inputs produce empty diff'
      );

      // @ Configuration error
      $thrown = false;
      try {
         new UnifiedStrict(['fromFile' => null]);
      }
      catch (Configuration $e) {
         $thrown = str_contains($e->getMessage(), 'fromFile');
      }
      yield assert(
         assertion: $thrown,
         description: 'missing fromFile throws Configuration'
      );

      // @ contextLines
      $Differ2 = new Differ(new UnifiedStrict([
         'fromFile'     => 'input.txt',
         'toFile'       => 'output.txt',
         'contextLines' => 1,
      ]));
      $output = $Differ2->diff("a\nb\nc\nd\ne\n", "a\nb\nC\nd\ne\n");
      yield assert(
         assertion: str_contains($output, "-c\n") && str_contains($output, "+C\n"),
         description: 'contextLines option respected (change present)'
      );
   }
);
