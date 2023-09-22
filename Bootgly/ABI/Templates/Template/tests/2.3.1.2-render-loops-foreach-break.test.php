<?php

use Bootgly\ABI\Templates\Template;


return [
   // @ configure
   'describe' => 'It should render loops: foreach (with break)',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      // @ Valid
      // with break
      $Template = new Template(
         <<<'TEMPLATE'
         @foreach ($items as $index => $item):
            @>. $index;

            @if ($index === 1):
               @break;
            @if;
         @foreach;
         TEMPLATE
      );
      $Template->render([
         'items' => ['a', 'b', 'c']
      ]);
      assert(
         assertion: $Template->output === <<<OUTPUT
         0
         1\n
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
