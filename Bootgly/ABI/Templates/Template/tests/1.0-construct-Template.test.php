<?php

use Bootgly\ABI\Templates\Template;


return [
   // @ configure
   'describe' => 'It should render simple ifs',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      $Template = new Template(
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
      $Template->render([
         'cool'     => true,
         'powerful' => true,
         // undef
         'poor'     => false
      ], $Template->Renderization::JIT_EVAL_MODE);

      $output = <<<OUTPUT
      Bootgly Template is cool!

      Bootgly is powerful!
      \n\n
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
