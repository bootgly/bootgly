<?php

use function assert;

use Bootgly\ABI\Templates\Template\Escaped;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'It should render `@!color:` tokens to background SGR codes',
   test: function () {
      // @ Normal backgrounds
      yield assert(
         assertion: Escaped::render('@!black:x') === "\e[40mx",
         description: '`@!black:` renders the normal black background'
      );
      yield assert(
         assertion: Escaped::render('@!red:x') === "\e[41mx",
         description: '`@!red:` renders the normal red background'
      );

      // @ Bright backgrounds (capitalized and uppercase)
      yield assert(
         assertion: Escaped::render('@!Black:x') === "\e[100mx",
         description: '`@!Black:` renders the bright black (gray) background'
      );
      yield assert(
         assertion: Escaped::render('@!WHITE:x') === "\e[107mx",
         description: '`@!WHITE:` renders the bright white background'
      );

      // @ Unknown names fall back to the default background
      yield assert(
         assertion: Escaped::render('@!nope:x') === "\e[49mx",
         description: 'Unknown background names render the default background'
      );

      // @ Composition with foreground and reset
      yield assert(
         assertion: Escaped::render('@!black:@#Cyan:x@;') === "\e[40m\e[96mx\e[0m",
         description: 'Background and foreground tokens compose; `@;` resets both'
      );
   }
);
