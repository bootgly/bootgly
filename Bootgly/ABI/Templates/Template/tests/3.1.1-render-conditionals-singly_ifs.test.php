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
      $Template11 = new Template(
         <<<'TEMPLATE'
         @if ($cool) :
         Bootgly Template is cool!
         @if;
         TEMPLATE
      );
      $Template11->render([
         'cool' => true,
      ]);
      assert(
         assertion: $Template11->output === <<<OUTPUT
         Bootgly Template is cool!\n
         OUTPUT,
         description: "Template #1.1: output does not match: \n`" . $Template11->output . '`'
      );

      $Template12 = new Template(
         <<<'TEMPLATE'
         @if $poor:
         Bootgly Template is poor!
         @else if $cool:
         Bootgly Template is cool!
         @if;
         TEMPLATE
      );
      $Template12->render([
         'poor' => false,
         'cool' => true,
      ]);
      assert(
         assertion: $Template12->output === <<<OUTPUT
         Bootgly Template is cool!\n
         OUTPUT,
         description: "Template #1.2: output does not match: \n`" . $Template12->output . '`'
      );

      // @ Neutral
      // ...

      // @ Invalid
      // ...

      return true;
   }
];
