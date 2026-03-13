<?php

use Bootgly\ABI\Templates\Template;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should render short conditional ifs: isSet',
   test: function () {
      // @ Valid
      // isSet false
      $Template = new Template(
         <<<'TEMPLATE'
         @if $items??:
            @> 'Items is set!';
         @else:
            @> 'Items is not set!';
         @if;
         TEMPLATE
      );
      $Template->render([
         'items' => null,
      ]);
      yield assert(
         assertion: $Template->output === <<<OUTPUT
         Items is not set!
         OUTPUT,
         description: "Template: output does not match: \n`" . $Template->output . '`'
      );

      // @ Neutral
      // ...

      // @ Invalid
      // ...
   }
);
