<?php

use Bootgly\ABI\Templates\Template;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should render components with only the default slot',
   test: new Assertions(function () {
      // !
      $path = Template::$path;
      Template::$path = __DIR__ . '/templates/';

      try {
         // @ Valid
         $Template = new Template('@component components/card:JUST-BODY@component;');
         $Template->render();

         yield new Assertion(
            description: 'Missing named slot yields empty; body fills the default slot',
            fallback: "Template output does not match: \n`" . $Template->output . '`'
         )
            ->assert(
               actual: $Template->output,
               expected: '<div>|JUST-BODY</div>'
            );

         // @ Invalid
         // ...
      }
      finally {
         // ! Restore
         Template::$path = $path;
      }
   })
);
