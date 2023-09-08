<?php

use Bootgly\ABI\Templates\Template;


return [
   // @ configure
   'describe' => 'It should render output vars @>> $x',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      // @ Valid
      $Template1 = new Template(
         <<<'TEMPLATE'
         Bootgly Template is @>> $a;!
         Bootgly Template is @>> $b;!
         Bootgly Template is @>> "$c";!
         Bootgly Template is @>> '$d';!
         TEMPLATE
      );
      $Template1->render([
         'a'     => 'easy',
         'b'     => true,
         #'c' => false
      ], $Template1->Renderization::JIT_EVAL_MODE);
      assert(
         assertion: $Template1->output === <<<'OUTPUT'
         Bootgly Template is easy!
         Bootgly Template is 1!
         Bootgly Template is !
         Bootgly Template is $d!
         OUTPUT,
         description: "Template #1: output does not match: \n`" . $Template1->output . '`'
      );

      $Template2 = new Template(
         <<<'TEMPLATE'
         Echo PHP Code: @>> 123456;!
         TEMPLATE
      );
      $Template2->render([], $Template2->Renderization::JIT_EVAL_MODE);
      assert(
         assertion: $Template2->output === <<<'OUTPUT'
         Echo PHP Code: 123456!
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
