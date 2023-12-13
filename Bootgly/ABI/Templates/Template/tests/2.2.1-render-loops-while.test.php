<?php

use Bootgly\ABI\Templates\Template;
use Bootgly\ACI\Tests\Assertions\Assertion;

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
            @> $tenth--;
         @while;
         TEMPLATE
      );
      $Template11->render([
         'tenth' => 10
      ]);
      Assertion::$description = 'Normal while';
      yield assert(
         assertion: $Template11->output === <<<'OUTPUT'
         10987654321
         OUTPUT,
         description: "Template #1.1: output does not match: \n`" . $Template11->output . '`'
      );

      // @ Neutral
      // Escaped
      $Template21 = new Template(
         <<<'TEMPLATE'
         @@while $tenth:
            @@> $tenth--;
         @@while;
         TEMPLATE
      );
      $Template21->render([
         'tenth' => 10
      ]);
      Assertion::$description = 'While escaped';
      yield assert(
         assertion: $Template21->output === <<<'OUTPUT'
         @while $tenth:
            @> $tenth--;
         @while;
         OUTPUT,
         description: "Template #2.1: output does not match: \n`" . $Template21->output . '`'
      );

      // @ Invalid
      // ...
   }
];
