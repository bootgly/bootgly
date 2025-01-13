<?php

use Bootgly\ABI\Templates\Template;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;

return [
   // @ configure
   'describe' => 'It should render loops: while',
   // @ simulate
   // ...
   // @ test
   'test' => new Assertions(function () {
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
      yield new Assertion(
         description: 'Normal while',
         fallback: "Template #1.1: output does not match: \n`" . $Template11->output . '`'
      )
         ->assert(
            actual: $Template11->output,
            expected: <<<'OUTPUT'
            10987654321
            OUTPUT
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
      yield new Assertion(
         description: 'While escaped',
         fallback: "Template #2.1: output does not match: \n`" . $Template21->output . '`'
      )
         ->assert(
            actual: $Template21->output,
            expected: <<<'OUTPUT'
            @while $tenth:
               @> $tenth--;
            @while;
            OUTPUT
         );

      // @ Invalid
      // ...
   })
];
