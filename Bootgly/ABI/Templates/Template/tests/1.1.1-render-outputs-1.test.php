<?php

use Bootgly\ABI\Templates\Template;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should render output vars @>> $x',
   test: function () {
      // @ Valid
      $Template11 = new Template(
         <<<'TEMPLATE'
         Bootgly Template is @> $a;!
         Bootgly Template is @> $b;!
         Bootgly Template is @> "$c";!
         Bootgly Template is @> '$d';!
         TEMPLATE
      );
      $Template11->render([
         'a'     => 'easy',
         'b'     => true,
         #'c' => false
      ]);
      yield assert(
         assertion: $Template11->output === <<<'OUTPUT'
         Bootgly Template is easy!
         Bootgly Template is 1!
         Bootgly Template is !
         Bootgly Template is $d!
         OUTPUT,
         description: "Template #1.1: output does not match: \n`" . $Template11->output . '`'
      );

      $Template12 = new Template(
         <<<'TEMPLATE'
         Echo PHP Code: @> 123456;!
         TEMPLATE
      );
      $Template12->render([]);
      yield assert(
         assertion: $Template12->output === <<<'OUTPUT'
         Echo PHP Code: 123456!
         OUTPUT,
         description: "Template #1.2: output does not match: \n`" . $Template12->output . '`'
      );

      // @ Neutral
      // Escaped
      $Template21 = new Template(
         <<<'TEMPLATE'
         Echo PHP Code: @@> 123456;!
         TEMPLATE
      );
      $Template21->render([]);
      yield assert(
         assertion: $Template21->output === <<<'OUTPUT'
         Echo PHP Code: @> 123456;!
         OUTPUT,
         description: "Template #2.1: output does not match: \n`" . $Template21->output . '`'
      );

      // @ Invalid
      // ...
   }
);
