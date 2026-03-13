<?php

use Bootgly\ABI\Templates\Template\Escaped;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should render style symbol',
   test: function () {
      // @ Valid
      $output = Escaped::render(
         <<<'TEMPLATE'
         @@: Is this text blinking? @;
         TEMPLATE
      );
      yield assert(
         assertion: $output === "\033[5mIs this text blinking?\033[0m",
         description: "Template: output does not match: \n`" . json_encode($output) . '`'
      );

      // @ Neutral
      // ...

      // @ Invalid
      // ...
   }
);
