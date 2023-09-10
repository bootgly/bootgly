<?php

use Bootgly\ABI\Templates\Template;


return [
   // @ configure
   'describe' => 'It should render loops: for',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      // @ Valid
      // Template #1
      $Template1 = new Template(<<<'TEMPLATE'
      @for ($i = 0; $i <= 10; $i++):
         @break in $i === 3;
         @> $i;
      @for;
      TEMPLATE);
      $Template1->render([]);
      assert(
         assertion: $Template1->output === <<<'OUTPUT'
         012
         OUTPUT,
         description: "Template #1: output does not match: \n`" . $Template1->output . '`'
      );

      // Template #2
      $Template2 = new Template(<<<'TEMPLATE'
      @for ($i = 0; $i <= 10; $i++):
         @continue in $i === 0;
         @> $i;
         @break;
      @for;
      TEMPLATE);
      $Template2->render([]);
      assert(
         assertion: $Template2->output === <<<'OUTPUT'
         1
         OUTPUT,
         description: "Template #2: output does not match: \n`" . $Template2->output . '`'
      );
      // @ Neutral
      // ...

      // @ Invalid
      // ...

      return true;
   }
];
