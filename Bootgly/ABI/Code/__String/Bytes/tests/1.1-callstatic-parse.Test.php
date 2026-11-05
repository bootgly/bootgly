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


/**
 * `Bytes::parse()` reads the units `format()` writes: binary multiples, every
 * spelling in any letter case, exact arithmetic rounded down, and a refusal —
 * never a silent default — for anything else.
 */
return new Test(
   description: 'Bytes parse - reads the units format() writes, exactly, and refuses the rest',
   test: function () {
      // @@ Every unit spelling, in three letter cases, is its own power of 1024
      $spellings = [
         0 => ['b'],
         1 => ['k', 'kb', 'kib'],
         2 => ['m', 'mb', 'mib'],
         3 => ['g', 'gb', 'gib'],
         4 => ['t', 'tb', 'tib'],
         5 => ['p', 'pb', 'pib'],
         6 => ['e', 'eb', 'eib'],
         // 10^-21 of the unit, floored: Z → 1, Y → 1208
         7 => ['z', 'zb', 'zib'],
         8 => ['y', 'yb', 'yib']
      ];
      foreach ($spellings as $pow => $units) {
         foreach ($units as $unit) {
            foreach ([$unit, strtoupper($unit), ucfirst($unit)] as $spelling) {
               [$size, $expected] = $pow <= 6
                  ? ["1 {$spelling}", 1 << (10 * $pow)]
                  : ["0.000000000000000000001 {$spelling}", $pow === 7 ? 1 : 1208];
               $bytes = Bytes::parse($size);

               yield assert(
                  assertion: $bytes === $expected,
                  description: "Bytes parse - '{$size}' is {$expected} bytes (got {$bytes})"
               );
            }
         }
      }

      // @@ Forms, fractions and boundaries — exact, rounded down
      $zeros = str_repeat('0', 63);
      $sizes = [
         '0' => 0,
         '512' => 512,
         '007' => 7,
         ' 3 m ' => 3_145_728,
         "3\tM" => 3_145_728,
         '1.5 GB' => 1_610_612_736,
         '1.5g' => 1_610_612_736,
         '0.5 KB' => 512,
         '0.5' => 0,
         '1.5 B' => 1,
         '0 ZB' => 0,
         '0.0000001 ZB' => 118_059_162_071_741,
         // # Precision past a float's 53 bits
         '1023.9999999999999999 B' => 1_023,
         '0.99999999999999999999 KB' => 1_023,
         '9007199254740993.0' => 9_007_199_254_740_993,
         // # The PHP_INT_MAX boundary, from every path
         '9223372036854775807' => PHP_INT_MAX,
         '9223372036854775807.0' => PHP_INT_MAX,
         '7 EB' => 8_070_450_532_247_928_832,
         '7.999999999999999999 EB' => 9_223_372_036_854_775_806,
         '8191 PB' => 9_222_246_136_947_933_184,
         '8191.9999 PB' => 9_223_371_924_264_785_123,
         '0.0078124999999999999999 ZB' => PHP_INT_MAX,
         // # The longest accepted input
         "{$zeros}1" => 1
      ];
      foreach ($sizes as $size => $expected) {
         $bytes = Bytes::parse((string) $size);

         yield assert(
            assertion: $bytes === $expected,
            description: "Bytes parse - '{$size}' is {$expected} bytes (got {$bytes})"
         );
      }

      // @@ Every exact power format() writes reads back as itself
      for ($pow = 0; $pow <= 6; $pow++) {
         $bytes = 1 << (10 * $pow);
         $size = Bytes::format($bytes, 0);

         yield assert(
            assertion: Bytes::parse($size) === $bytes,
            description: "Bytes parse - reads format()'s '{$size}' back as {$bytes}"
         );
      }

      // @@ Invalid — each refused by name
      $refusals = [
         '' => 'Invalid byte size',
         '   ' => 'Invalid byte size',
         '-1' => 'Invalid byte size',
         '+1' => 'Invalid byte size',
         '.5' => 'Invalid byte size',
         '1.' => 'Invalid byte size',
         '1e3' => 'Invalid byte size',
         '1_024' => 'Invalid byte size',
         '1 K B' => 'Invalid byte size',
         '1,5 MB' => 'Invalid byte size',
         "3\nM" => "Invalid byte size '3\\nM'",
         "\n3M" => "Invalid byte size '\\n3M'",
         "3M\n" => "Invalid byte size '3M\\n'",
         "1\x00K" => "Invalid byte size '1\\000K'",
         "1\x7fK" => "Invalid byte size '1\\177K'",
         // a right-to-left override cannot reorder the message
         "1\xe2\x80\xaeK" => "Invalid byte size '1\\342\\200\\256K'",
         // a literal backslash and a quote stay distinguishable
         '1\\n' => "Invalid byte size '1\\\\n'",
         "1'x" => "Invalid byte size '1\\'x'",
         '1 XB' => "Unknown byte unit 'XB'",
         '1 bytes' => "Unknown byte unit 'bytes'",
         '8 EB' => 'exceeds PHP_INT_MAX',
         '8.0 EB' => 'exceeds PHP_INT_MAX',
         '8.5 EB' => 'exceeds PHP_INT_MAX',
         '8192 PB' => 'exceeds PHP_INT_MAX',
         '9223372036854775808' => 'exceeds PHP_INT_MAX',
         '99999999999999999999' => 'exceeds PHP_INT_MAX',
         '1 ZB' => 'exceeds PHP_INT_MAX',
         '0.0078125 ZB' => 'exceeds PHP_INT_MAX',
         '0.5 YB' => 'exceeds PHP_INT_MAX',
         // fractions with no exact binary end, past the boundary by a hair
         '0.3 YB' => 'exceeds PHP_INT_MAX',
         '0.0078125000000000000001 ZB' => 'exceeds PHP_INT_MAX',
         "0{$zeros}1" => 'longer than 64 bytes'
      ];
      foreach ($refusals as $size => $message) {
         $refused = '';
         try {
            Bytes::parse((string) $size);
         }
         catch (InvalidArgumentException $Exception) {
            $refused = $Exception->getMessage();
         }

         yield assert(
            assertion: str_contains($refused, $message),
            description: "Bytes parse - '{$size}' is refused ({$message}), got '{$refused}'"
         );
      }

      // @ A refusal quotes at most LIMIT bytes of the input
      $refused = '';
      try {
         Bytes::parse(str_repeat('9', 100_000));
      }
      catch (InvalidArgumentException $Exception) {
         $refused = $Exception->getMessage();
      }

      yield assert(
         assertion: strlen($refused) < 2 * Bytes::LIMIT,
         description: 'Bytes parse - a 100 KB input gives a bounded refusal message'
      );
   }
);
