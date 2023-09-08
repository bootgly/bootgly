<?php

use Bootgly\ABI\Templates\Template;


return [
   // @ configure
   'describe' => 'It should render simple ifs',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      // @ Valid
      $Template1 = new Template(
         <<<'TEMPLATE'
         @if ($cool) :
         Bootgly Template is cool!
         @if;

         @if ($powerful) :
         Bootgly is powerful!
         @if;

         @if ($undef) :
         Bootgly is ?!
         @if;

         @if ($poor) :
         Bootgly is poor!
         @if;
         TEMPLATE
      );
      $Template1->render([
         'cool'     => true,
         'powerful' => true,
         // undef
         'poor'     => false
      ], $Template1->Renderization::JIT_EVAL_MODE);
      assert(
         assertion: $Template1->output === <<<OUTPUT
         Bootgly Template is cool!

         Bootgly is powerful!
         \n\n
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
