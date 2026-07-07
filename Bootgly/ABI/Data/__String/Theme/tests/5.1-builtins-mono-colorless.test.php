<?php

use Bootgly\ABI\Data\__String\Theme;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'mono emits no ANSI; dark and light differ (bright vs normal)',
   test: function () {
      $Mono = new Theme(Theme::MONO);
      yield assert(
         assertion: $Mono->open('error') === '',
         description: 'mono open(error) is empty'
      );
      yield assert(
         assertion: $Mono->apply('error', 'X') === 'X',
         description: 'mono apply(error, X) = X (colorless)'
      );

      $Dark  = new Theme(Theme::DARK);
      $Light = new Theme(Theme::LIGHT);
      yield assert(
         assertion: $Dark->open('error') === "\e[91m",
         description: 'dark error = bright red'
      );
      yield assert(
         assertion: $Light->open('error') === "\e[31m",
         description: 'light error = normal red'
      );
      yield assert(
         assertion: $Dark->open('error') !== $Light->open('error'),
         description: 'dark error != light error'
      );
   }
);
