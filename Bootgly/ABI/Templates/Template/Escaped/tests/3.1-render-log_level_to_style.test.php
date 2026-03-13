<?php

use Bootgly\ABI\Templates\Template\Escaped;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should render log levels to style',
   test: function () {
      // @ Valid
      $output = Escaped::render(
         <<<'TEMPLATE'
         @:warning: Warning! Bootgly is very cool! @;
         TEMPLATE
      );
      yield assert(
         assertion: $output === "\033[95m Warning! Bootgly is very cool!\033[0m",
         description: "Template: output does not match: \n`" . json_encode($output) . '`'
      );

      // @ Neutral
      // ...

      // @ Invalid
      // ...
   }
);
