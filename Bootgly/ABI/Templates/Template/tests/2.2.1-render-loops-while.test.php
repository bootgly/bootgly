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
      $Template11 = new Template(
         <<<'TEMPLATE'
         @while $tenth:
            @>> $tenth--;
         @while;
         TEMPLATE
      );
      $Template11->render([
         'tenth' => 10
      ], $Template11->Renderization::JIT_EVAL_MODE);
      assert(
         assertion: $Template11->output === <<<'OUTPUT'
            10   9   8   7   6   5   4   3   2   1
         OUTPUT,
         description: "Template #1.1: output does not match: \n`" . $Template11->output . '`'
      );

      // @ Neutral
      // Escaped
      $Template21 = new Template(
         <<<'TEMPLATE'
         @@while $tenth:
            @@>> $tenth--;
         @@while;
         TEMPLATE
      );
      $Template21->render([
         'tenth' => 10
      ], $Template21->Renderization::JIT_EVAL_MODE);
      assert(
         assertion: $Template21->output === <<<'OUTPUT'
         @while $tenth:
            @>> $tenth--;
         @while;
         OUTPUT,
         description: "Template #2.1: output does not match: \n`" . $Template21->output . '`'
      );

      // @ Invalid
      // ...

      return true;
   }
];
