<?php

use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Calculators: the memory guard selects Memory for skewed inputs',
   skip: DIRECTORY_SEPARATOR === '\\' || function_exists('exec') === false,
   test: function () {
      // ! A 100 × 100,000 line diff: the Time calculator's DP matrix needs ~268 MB,
      //   so under a 128 MB limit the guard must select the Memory calculator (DIFF-1).
      //   A child process keeps the probe hermetic — an OOM there cannot kill the suite.
      $autoload = BOOTGLY_ROOT_DIR . 'vendor/autoload.php';
      $code = sprintf(<<<'PHP'
      require %s;
      $from = '';
      for ($i = 0; $i < 100; $i++) { $from .= "from line $i\n"; }
      $to = '';
      for ($i = 0; $i < 100000; $i++) { $to .= "to line $i\n"; }
      $Differ = new Bootgly\ABI\Differ(new Bootgly\ABI\Differ\Outputs\UnifiedStrict([
         'fromFile' => 'a.txt',
         'toFile' => 'b.txt'
      ]));
      echo 'OK:', strlen($Differ->diff($from, $to));
      PHP, var_export($autoload, true));

      // @
      $lines = [];
      $exit = 1;
      exec(
         PHP_BINARY . ' -d memory_limit=128M -r ' . escapeshellarg($code) . ' 2>&1',
         $lines,
         $exit
      );
      $tail = (string) end($lines);

      // :
      yield assert(
         assertion: $exit === 0 && str_starts_with($tail, 'OK:'),
         description: "Skewed diff must complete under a 128M limit (exit=$exit, tail=`$tail`)"
      );

      $bytes = (int) substr($tail, 3);
      yield assert(
         assertion: $bytes > 1_000_000,
         description: "Diff output carries the 100,000 inserted lines (got $bytes bytes)"
      );
   }
);
