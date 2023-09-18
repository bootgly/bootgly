<?php

use Bootgly\ABI\Templates\Template;


return [
   // @ configure
   'describe' => 'It should render loops: nested foreachs with $@',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      // @ Valid
      $Template11 = new Template(
         <<<'TEMPLATE'
         @foreach ($items as $group => $subitems):
            @if ($@->isFirst):
               @>. 'First!';
            @if;

            @.>. "Level #$@->depth - key: " . $@->key;

            @foreach $subitems as $subitem:
               @>. "Level #2 - value: " . $@->value;

               @if ($@->Parent->remaining === 0 && $@->remaining === 0):
                  @> "Level #1 - key: " . $@->Parent->key . PHP_EOL;
               @if;
            @foreach;

            @if $@->isLast:
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
         Level #1 - key: c

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
