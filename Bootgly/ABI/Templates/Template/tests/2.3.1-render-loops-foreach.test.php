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
         @foreach ($items as $key => $value):
            @if ($@->isFirst):
               @>. 'First!';
            @if;

            @.>. "Level #1 - key: " . $@->key;

            @foreach ($value as $subitems):
               @>. "Level #2 - value: " . $@->value;
            @foreach;

            @if ($@->isLast):
               @.> 'Last!';
            @if;
         @foreach;
         TEMPLATE
      );
      $Template11->render([
         'items' => [
            'a' => ['orange', 'lemon', 'watermelon'],
            'b' => ['mouse', 'keyboard', 'monitor'],
            'c' => ['helmet', 'legs', 't-shirt']
         ]
      ]);
      assert(
         assertion: $Template11->output === <<<OUTPUT
         First!

         Level #1 - key: a
         Level #2 - value: orange
         Level #2 - value: lemon
         Level #2 - value: watermelon

         Level #1 - key: b
         Level #2 - value: mouse
         Level #2 - value: keyboard
         Level #2 - value: monitor

         Level #1 - key: c
         Level #2 - value: helmet
         Level #2 - value: legs
         Level #2 - value: t-shirt

         Last!
         OUTPUT,
         description: "Template #1.1: output does not match: \n`" . $Template11->output . '`'
      );

      // @ Neutral
      // Escaped
      // ...

      // @ Invalid
      // ...

      return true;
   }
];
