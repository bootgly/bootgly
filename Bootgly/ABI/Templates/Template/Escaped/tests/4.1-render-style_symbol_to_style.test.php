<?php

use Bootgly\ABI\Templates\Template\Escaped;


return [
   // @ configure
   'describe' => 'It should render style symbol',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      // @ Valid
      $output = Escaped::render(
         <<<'TEMPLATE'
         @@: Is this text blinking? @;
         TEMPLATE
      );
      assert(
         assertion: $output === "\033[5mIs this text blinking?\033[0m",
         description: "Template: output does not match: \n`" . json_encode($output) . '`'
      );

      // @ Neutral
      // ...

      // @ Invalid
      // ...

      return true;
   }
];
