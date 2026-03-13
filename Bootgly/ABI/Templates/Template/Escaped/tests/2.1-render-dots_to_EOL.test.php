<?php

use Bootgly\ABI\Templates\Template\Escaped;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should render dots (...) to EOL',
   test: function () {
      // @ Valid
      $output = Escaped::render(
         <<<'TEMPLATE'
         2 Breaklines after this text @..;
         TEMPLATE
      );
      yield assert(
         assertion: $output === "2 Breaklines after this text \n\n",
         description: "Template: output does not match: \n`" . json_encode($output) . '`'
      );

      // @ Neutral
      // ...

      // @ Invalid
      // ...
   }
);
