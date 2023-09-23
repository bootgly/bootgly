<?php

use Bootgly\ABI\Templates\Template\Escaped;


return [
   // @ configure
   'describe' => 'It should render color name to color',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      // @ Valid
      $output = Escaped::render(
         <<<'TEMPLATE'
         @#red: Text with color red. @;
         TEMPLATE
      );
      assert(
         assertion: $output === "\033[31m Text with color red.\033[0m",
         description: "Template: output does not match: \n`" . json_encode($output) . '`'
      );

      // @ Neutral
      // ...

      // @ Invalid
      // ...

      return true;
   }
];
