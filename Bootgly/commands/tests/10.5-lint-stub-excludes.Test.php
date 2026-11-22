<?php

namespace Bootgly\commands;


use const BOOTGLY_ROOT_BASE;
use function assert;
use function json_encode;

use Bootgly\ABI\Syntax\Lint;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'lint stub: a project\'s fixtures are excluded at any depth under tests/, its code is not',
   test: function () {
      // ! The stub a kit lays down as projects/bootgly.Lint.php
      $Lint = Lint::load(BOOTGLY_ROOT_BASE . '/Bootgly/commands/stubs/bootgly.Lint.php');

      // @@ path => excluded
      $cases = [
         'App/tests/fixtures/x.php' => true,
         'App/tests/Integration/fixtures/x.php' => true,
         'App/tests/Integration/fixtures/deep/x.php' => true,
         'vendor/a/b.php' => true,
         'App/x.php' => false,
         'App/tests/Integration/x.Test.php' => false,
         'App/contests/fixtures/x.php' => false,
         'App/fixtures/x.php' => false,
      ];
      $wrong = [];
      foreach ($cases as $path => $expected) {
         if ($Lint->exclude($path) !== $expected) {
            $wrong[] = $path;
         }
      }

      yield assert(
         assertion: $wrong === [],
         description: 'Every path must be excluded exactly when it sits in fixtures/ under tests/, wrong: ' . json_encode($wrong)
      );
   }
);
