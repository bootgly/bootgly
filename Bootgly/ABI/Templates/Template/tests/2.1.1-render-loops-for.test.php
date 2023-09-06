<?php

use Bootgly\ABI\Templates\Template;


return [
   // @ configure
   'describe' => 'It should render loops: for',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      $Template = new Template(<<<'TEMPLATE'
      @for ($i = 0; $i <= 10; $i++):
         @break in $i === 3;
         @>> $i;
      @for;
      TEMPLATE);
      $Template->render([], $Template->Renderization::JIT_EVAL_MODE);

      $output = <<<OUTPUT
            0      1      2   
      OUTPUT;

      // @ Valid
      assert(
         assertion: $Template->output === $output,
         description: "Rendered output does not match: \n`" . $Template->output . '`'
      );

      // @ Neutral
      // ...

      // @ Invalid
      // ...

      return true;
   }
];
