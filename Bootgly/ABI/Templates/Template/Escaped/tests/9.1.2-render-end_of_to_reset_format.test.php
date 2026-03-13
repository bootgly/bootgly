<?php

use Bootgly\ABI\Templates\Template\Escaped;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should render reset character (alt)',
   test: function () {
      // @ Valid
      $output = Escaped::render(
         <<<'TEMPLATE'
         Reseting formatting?*@
         TEMPLATE
      );
      yield assert(
         assertion: $output === "Reseting formatting?\033[0m",
         description: "Assertion #2: output does not match: \n`" . json_encode($output) . '`'
      );

      // @ Neutral
      // ...

      // @ Invalid
      // ...
   }
);
