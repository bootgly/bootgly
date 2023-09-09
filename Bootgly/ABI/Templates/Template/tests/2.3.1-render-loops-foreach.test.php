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
      $Template11 = new Template(
         <<<'TEMPLATE'
         @foreach ($items as $key => $item):
            @if ($@->isFirst):
               @>. 'First!';
            @if;

            @.>. $@->index;

            @if ($@->isLast):
               @.> 'Last!';
            @if;
         @foreach;
         TEMPLATE
      );
      $Template11->render([
         'items' => ['a', 'b', 'c']
      ], $Template11->Renderization::JIT_EVAL_MODE);
      assert(
         assertion: $Template11->output === <<<OUTPUT
         First!

         0

         1

         2

         Last!
         OUTPUT,
         description: "Template #1.1: output does not match: \n`" . $Template11->output . '`'
      );

      // @ Neutral
      // Escaped
      // ...

      // @ Invalid
      // ...

      return true;
   }
];
