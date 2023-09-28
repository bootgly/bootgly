<?php

use Bootgly\ABI\Templates\Template;


return [
   // @ configure
   'describe' => 'It should render short conditional ifs: not empty',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      // @ Valid
      // Not Empty false
      $Template = new Template(
         <<<'TEMPLATE'
         @if $items?:
            @> 'Some items found!';
         @else:
            @> 'No items found.';
         @if;
         TEMPLATE
      );
      $Template->render([
         'items' => 0,
      ]);
      yield assert(
         assertion: $Template->output === <<<OUTPUT
         No items found.
         OUTPUT,
         description: "Template: output does not match: \n`" . $Template->output . '`'
      );

      // @ Neutral
      // ...

      // @ Invalid
      // ...
   }
];
