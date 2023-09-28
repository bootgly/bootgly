<?php

use Bootgly\ABI\Templates\Template;


return [
   // @ configure
   'describe' => 'It should render loops: foreach with metavar ($@)',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      // @ Valid
      $Template11 = new Template(
         <<<'TEMPLATE'
         @foreach ($items as $item):
            @>. $@->index;
         @foreach;
         TEMPLATE
      );
      $Template11->render([
         'items' => ['a', 'b', 'c']
      ]);
      yield assert(
         assertion: $Template11->output === <<<OUTPUT
         0
         1
         2\n
         OUTPUT,
         description: "Template #1.1: output does not match: \n`" . $Template11->output . '`'
      );

      // @ Neutral
      // Escaped
      // ...

      // @ Invalid
      // ...
   }
];
