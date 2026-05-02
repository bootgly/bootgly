<?php

use function json_encode;

use Bootgly\ABI\Differ;
use Bootgly\ABI\Differ\Outputs\Escaped;
use Bootgly\ABI\Differ\Outputs\UnifiedStrict;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Outputs\\Escaped: exact ANSI unified diff contract',
   test: function (): Generator {
      $Differ = new Differ(new Escaped(new UnifiedStrict([
         'fromFile' => 'before.txt',
         'toFile'   => 'after.txt',
      ])));

      $output = $Differ->diff(
         "alpha\nbeta\ngamma\n",
         "alpha\nBETA\ngamma\n",
      );

      $expected = "\033[1m--- before.txt\033[0m\n"
         . "\033[1m+++ after.txt\033[0m\n"
         . "\033[36m@@ -1,3 +1,3 @@\033[0m\n"
         . " alpha\n"
         . "\033[31m-beta\033[0m\n"
         . "\033[32m+BETA\033[0m\n"
         . " gamma\n";

      yield assert(
         assertion: $output === $expected,
         description: 'escaped output matches the full ANSI unified diff: ' . json_encode($output)
      );
   }
);
