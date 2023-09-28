<?php

use Bootgly\ABI\Templates\Template;


return [
   // @ configure
   'describe' => 'It should render loops: foreach',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      // @ Valid
      $Template = new Template(
         <<<'TEMPLATE'
         @foreach ($items as $index => $item):
            @>. $index;
         @foreach;
         TEMPLATE
      );
      $Template->render([
         'items' => ['a', 'b', 'c']
      ]);
      yield assert(
         assertion: $Template->output === <<<OUTPUT
         0
         1
         2\n
         OUTPUT,
         description: "Template: output does not match: \n`" . $Template->output . '`'
      );

      // @ Neutral
      // Escaped
      // ...

      // @ Invalid
      // ...
   }
];
