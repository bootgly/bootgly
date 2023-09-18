<?php

use Bootgly\ABI\Templates\Template;


return [
   // @ configure
   'describe' => 'It should render loops: foreach',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      // @ Valid
      $Template11 = new Template(
         <<<'TEMPLATE'
         @foreach ($items as $index => $item):
            @>. $index;
         @foreach;
         TEMPLATE
      );
      $Template11->render([
         'items' => ['a', 'b', 'c']
      ]);
      assert(
         assertion: $Template11->output === <<<OUTPUT
         0
         1
         2\n
         OUTPUT,
         description: "Template #1.1: output does not match: \n`" . $Template11->output . '`'
      );

      // with break
      $Template12 = new Template(
         <<<'TEMPLATE'
         @foreach ($items as $index => $item):
            @>. $index;

            @if ($index === 1):
               @break;
            @if;
         @foreach;
         TEMPLATE
      );
      $Template12->render([
         'items' => ['a', 'b', 'c']
      ]);
      assert(
         assertion: $Template12->output === <<<OUTPUT
         0
         1\n
         OUTPUT,
         description: "Template #1.1: output does not match: \n`" . $Template12->output . '`'
      );

      // @ Neutral
      // Escaped
      // ...

      // @ Invalid
      // ...

      return true;
   }
];
