<?php

use Bootgly\ABI\Code\__String\Theme;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'open() opens, close() resets, apply() wraps (dark theme)',
   test: function () {
      $Theme = new Theme(Theme::DARK);

      $open  = $Theme->open('error');
      $apply = $Theme->apply('error', 'X');
      $close = $Theme->close('error');

      yield assert(
         assertion: $open === "\e[91m",
         description: 'open(error) = ' . str_replace("\e", '\\e', $open)
      );
      yield assert(
         assertion: $apply === "\e[91mX\e[0m",
         description: 'apply(error, X) = ' . str_replace("\e", '\\e', $apply)
      );
      yield assert(
         assertion: $close === "\e[0m",
         description: 'close(error) = ' . str_replace("\e", '\\e', $close)
      );
   }
);
