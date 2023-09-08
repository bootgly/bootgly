<?php

use Bootgly\ABI\Templates\Template;


return [
   // @ configure
   'describe' => 'It should render loops: while',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      // @ Valid
      $Template1 = new Template(<<<'TEMPLATE'
      @while $tenth:
         @>> $tenth--;
      @while;
      TEMPLATE);
      $Template1->render([
         'tenth' => 10
      ], $Template1->Renderization::JIT_EVAL_MODE);
      assert(
         assertion: $Template1->output === <<<'OUTPUT'
         10   9   8   7   6   5   4   3   2   1
      OUTPUT,
         description: "Template #1: output does not match: \n`" . $Template1->output . '`'
      );

      // @ Neutral
      // ...

      // @ Invalid
      // ...

      return true;
   }
];
