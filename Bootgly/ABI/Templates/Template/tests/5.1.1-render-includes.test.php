<?php

use Bootgly\ABI\Templates\Template;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should render includes sharing the current scope',
   test: new Assertions(function () {
      // !
      $path = Template::$path;
      Template::$path = __DIR__ . '/templates/';

      try {
         // @ Valid
         $Template = new Template('pre|@include partials/alert;|post');
         $Template->render(['level' => 'warn']);

         yield new Assertion(
            description: 'Included partial sees the parent scope',
            fallback: "Template output does not match: \n`" . $Template->output . '`'
         )
            ->assert(
               actual: $Template->output,
               expected: 'pre|[alert:warn]|post'
            );

         // @ Neutral
         // Escaped directive emits its literal form
         $Template2 = new Template('@@include partials/alert;');
         $Template2->render();

         yield new Assertion(
            description: 'Escaped @include emits the literal directive',
            fallback: "Template #2: output does not match: \n`" . $Template2->output . '`'
         )
            ->assert(
               actual: $Template2->output,
               expected: '@include partials/alert;'
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
