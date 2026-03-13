<?php

use Bootgly\ABI\Templates\Template;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should render loops: foreach (with break)',
   test: function () {
      // @ Valid
      // with break
      $Template = new Template(
         <<<'TEMPLATE'
         @foreach ($items as $index => $item):
            @>. $index;

            @if ($index === 1):
               @break;
            @if;
         @foreach;
         TEMPLATE
      );
      $Template->render([
         'items' => ['a', 'b', 'c']
      ]);
      yield assert(
         assertion: $Template->output === <<<OUTPUT
         0
         1\n
         OUTPUT,
         description: "Template: output does not match: \n`" . $Template->output . '`'
      );

      // @ Neutral
      // Escaped
      // ...

      // @ Invalid
      // ...
   }
);
