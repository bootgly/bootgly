<?php

use Bootgly\ABI\Templates\Template;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should render loops: foreach (with continue)',
   test: function () {
      // @ Valid
      // with break
      $Template = new Template(
         <<<'TEMPLATE'
         @foreach ($items as $index => $item):
            @if ($index === 0):
               @continue;
            @if;

            @>. $index;
         @foreach;
         TEMPLATE
      );
      $Template->render([
         'items' => ['a', 'b', 'c']
      ]);
      yield assert(
         assertion: $Template->output === <<<OUTPUT
         1
         2\n
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
