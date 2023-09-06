<?php

use Bootgly\ABI\Templates\Template;


return [
   // @ configure
   'describe' => 'It should render output vars @>> $x',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      $Template = new Template(
         <<<'TEMPLATE'
         Bootgly Template is @>> $a;!
         Bootgly Template is @>> $b;!
         Bootgly Template is @>> $c;!
         TEMPLATE
      );
      $Template->render([
         'a'     => 'easy',
         'b'     => true,
         #'c' => false
      ], $Template->Renderization::JIT_EVAL_MODE);

      $output = <<<OUTPUT
      Bootgly Template is easy!
      Bootgly Template is 1!
      Bootgly Template is !
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
