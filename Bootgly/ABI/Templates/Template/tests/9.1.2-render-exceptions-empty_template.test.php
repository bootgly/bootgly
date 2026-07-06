<?php

use Bootgly\ABI\Templates\Template;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should render an empty template as an empty string',
   test: new Assertions(function () {
      // @ Valid
      $Template = new Template('');
      $rendered = $Template->render();

      yield new Assertion(
         description: 'Empty template renders empty output',
         fallback: "Rendered: `{$rendered}`"
      )
         ->assert(
            actual: $rendered,
            expected: ''
         );

      // @ Invalid
      // ...
   })
);
