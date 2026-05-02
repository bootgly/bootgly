<?php

use Bootgly\ABI\Differ;
use Bootgly\ABI\Differ\Outputs\Only;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Outputs\\Only: header + only changed lines',
   test: function () {
      $Differ = new Differ(new Only);

      $output = $Differ->diff("foo\n", "bar\n");
      yield assert(
         assertion: str_contains($output, "--- Original\n+++ New\n"),
         description: 'has header'
      );
      yield assert(
         assertion: str_contains($output, "-foo\n") && str_contains($output, "+bar\n"),
         description: 'shows -foo and +bar'
      );
      yield assert(
         assertion: ! str_contains($output, '@@'),
         description: 'no hunk markers in DiffOnly output'
      );
   }
);
