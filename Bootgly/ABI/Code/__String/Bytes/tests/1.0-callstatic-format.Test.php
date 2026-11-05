<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use Bootgly\ABI\Code\__String\Bytes;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Bytes format - the largest unit that keeps the value at or above 1, up to YB',
   test: function () {
      // @ Valid
      $sizes = [
         // # One per unit
         [512, 2, '512 B'],
         [1024, 2, '1 KB'],
         [1024 ** 2, 2, '1 MB'],
         [1024 ** 3, 2, '1 GB'],
         [1024 ** 4, 2, '1 TB'],
         [1024 ** 5, 2, '1 PB'],
         // PHP_INT_MAX is just under 8 EB
         [PHP_INT_MAX, 2, '8 EB'],
         [1024.0 ** 7, 2, '1 ZB'],
         [1024.0 ** 8, 2, '1 YB'],
         // past the last unit stays in YB, never an out-of-range index
         [1024.0 ** 9, 2, '1024 YB'],
         // # Precision
         [1536, 1, '1.5 KB'],
         [1500, 3, '1.465 KB'],
         [1500, 0, '1 KB'],
         [1_610_612_736, 0, '2 GB'],
         [1_610_612_736, 3, '1.5 GB'],
         // # Below one byte stays in B
         [0.5, 2, '0.5 B'],
         [0, 2, '0 B'],
         // @ Invalid
         // negative counts clamp to zero, negative zero included
         [-5, 2, '0 B'],
         [-0.0, 2, '0 B']
      ];

      // @@
      foreach ($sizes as [$bytes, $precision, $expected]) {
         $formatted = Bytes::format($bytes, $precision);

         yield assert(
            assertion: $formatted === $expected,
            description: "Bytes format - {$bytes} at precision {$precision} is '{$expected}' (got '{$formatted}')"
         );
      }

      // @ The default precision is two decimals
      $formatted = Bytes::format(1500);

      yield assert(
         assertion: $formatted === '1.46 KB',
         description: "Bytes format - 1500 at the default precision is '1.46 KB' (got '{$formatted}')"
      );
   }
);
