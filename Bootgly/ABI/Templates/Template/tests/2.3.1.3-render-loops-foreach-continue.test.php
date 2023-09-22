<?php

use Bootgly\ABI\Templates\Template;


return [
   // @ configure
   'describe' => 'It should render loops: foreach (with continue)',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      // @ Valid
      // with break
      $Template = new Template(
         <<<'TEMPLATE'
         @foreach ($items as $index => $item):
            @if ($index === 0):
               @continue;
            @if;

            @>. $index;
         @foreach;
         TEMPLATE
      );
      $Template->render([
         'items' => ['a', 'b', 'c']
      ]);
      assert(
         assertion: $Template->output === <<<OUTPUT
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

      return true;
   }
];
