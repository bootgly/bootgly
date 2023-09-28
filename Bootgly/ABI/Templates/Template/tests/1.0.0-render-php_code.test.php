<?php

use Bootgly\ABI\Templates\Template;

return [
   // @ configure
   'describe' => 'It should render pure PHP',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      // @ Valid
      $Template11 = new Template(
         <<<'TEMPLATE'
         @:
         $framework = 'Bootgly Template Engine';
         echo $framework;
         @;
         TEMPLATE
      );
      $Template11->render();
      yield assert(
         assertion: $Template11->output === <<<'OUTPUT'
         Bootgly Template Engine
         OUTPUT,
         description: "Template #1.1: output does not match: \n`" . $Template11->output . '`'
      );

      // Inline
      $Template12 = new Template(
      <<<'TEMPLATE'
         @: $framework = 'Bootgly Template Engine Inline'; echo $framework; @;
         TEMPLATE
      );
      $Template12->render();
      yield assert(
         assertion: $Template12->output === <<<'OUTPUT'
         Bootgly Template Engine Inline
         OUTPUT,
         description: "Template #1.2: output does not match: \n`" . $Template12->output . '`'
      );

      // @ Neutral
      // ...

      // @ Invalid
      // ...
   }
];
