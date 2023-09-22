<?php

use Bootgly\ABI\Templates\Template;


return [
   // @ configure
   'describe' => 'It should render simple switch',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      // @ Valid
      $Template11 = new Template(
         <<<'TEMPLATE'
         @switch $bootglyIs:
            @case 'foo':
               @break;
            @case 'cool':
               @> 'Bootgly Template is cool!';
               @break;
            @default:
               @> '...';
         @switch;
         TEMPLATE
      );
      $Template11->render([
         'bootglyIs' => 'cool',
      ]);
      assert(
         assertion: $Template11->output === <<<OUTPUT
         Bootgly Template is cool!
         OUTPUT,
         description: "Template: output does not match: \n`" . $Template11->output . '`'
      );

      // @ Neutral
      // ...

      // @ Invalid
      // ...

      return true;
   }
];
