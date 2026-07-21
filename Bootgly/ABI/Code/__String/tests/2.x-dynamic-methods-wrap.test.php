<?php

use Bootgly\ABI\Code\__String;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should wrap strings by visible columns (ANSI + multibyte aware)',
   test: function () {
      // @ Greedy ASCII wrap at the width
      $result = __String::wrap('alpha beta gamma delta', 11);

      yield assert(
         assertion: $result === "alpha beta\ngamma delta",
         description: 'Greedy fill wraps at the visible width'
      );

      // @ Multibyte display width — CJK characters count 2 columns
      $result = __String::wrap('世界 世界 世界', 5);

      yield assert(
         assertion: $result === "世界\n世界\n世界",
         description: 'CJK pairs (4 columns) wrap by display width, not by character count'
      );

      // @ ANSI escapes are zero-width and the SGR state reopens after breaks
      $result = __String::wrap("\e[1malpha beta\e[0m", 5);

      yield assert(
         assertion: $result === "\e[1malpha\e[0m\n\e[1mbeta\e[0m",
         description: 'Open styles reset before the break and reopen on the next line'
      );

      // @ Overlong words — kept whole by default, hard-split with cut
      $result = __String::wrap('abcdefghij', 4);

      yield assert(
         assertion: $result === 'abcdefghij',
         description: 'cut: false keeps an overlong word whole (overflow allowed)'
      );

      $result = __String::wrap('abcdefghij', 4, cut: true);

      yield assert(
         assertion: $result === "abcd\nefgh\nij",
         description: 'cut: true hard-splits an overlong word by columns'
      );

      // @ Custom break string
      $result = __String::wrap('aa bb', 2, '<br>');

      yield assert(
         assertion: $result === 'aa<br>bb',
         description: 'Inserted breaks use the custom break string'
      );

      // @ Pre-existing newlines are preserved as-is
      $result = __String::wrap("aa\nbb", 10);

      yield assert(
         assertion: $result === "aa\nbb",
         description: 'Lines that already fit stay untouched'
      );

      // @ Non-positive widths return the string unchanged
      $result = __String::wrap('alpha beta', 0);

      yield assert(
         assertion: $result === 'alpha beta',
         description: 'Width 0 is a no-op'
      );

      // @ Instance form resolves through __call
      $String = new __String('alpha beta gamma delta');
      $result = $String->wrap(11);

      yield assert(
         assertion: $result === "alpha beta\ngamma delta",
         description: 'Instance wrap() forwards to the static engine'
      );
   }
);
