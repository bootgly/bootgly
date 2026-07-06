<?php

use Bootgly\ABI\Templates\Template;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should render includes with explicit data winning over scope',
   test: new Assertions(function () {
      // !
      $path = Template::$path;
      Template::$path = __DIR__ . '/templates/';

      try {
         // @ Valid
         $Template = new Template("@include partials/alert with ['level' => 'explicit'];");
         $Template->render(['level' => 'implicit']);

         yield new Assertion(
            description: 'Explicit include data wins over the parent scope',
            fallback: "Template output does not match: \n`" . $Template->output . '`'
         )
            ->assert(
               actual: $Template->output,
               expected: '[alert:explicit]'
            );

         // Semicolons inside quoted strings survive the with-payload parsing
         $Template2 = new Template("@include partials/alert with ['level' => 'a;b'];");
         $Template2->render();

         yield new Assertion(
            description: 'Semicolon inside a with string does not truncate the payload',
            fallback: "Template #2: output does not match: \n`" . $Template2->output . '`'
         )
            ->assert(
               actual: $Template2->output,
               expected: '[alert:a;b]'
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
