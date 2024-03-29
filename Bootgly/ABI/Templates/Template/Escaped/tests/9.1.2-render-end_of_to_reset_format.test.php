<?php

use Bootgly\ABI\Templates\Template\Escaped;


return [
   // @ configure
   'describe' => 'It should render reset character (alt)',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
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
];
