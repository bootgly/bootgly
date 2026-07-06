<?php

use Bootgly\ABI\Templates\Template;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should render inheritance: yield default content override',
   test: new Assertions(function () {
      // !
      $path = Template::$path;
      Template::$path = __DIR__ . '/templates/';

      try {
         // @ Valid
         // An inline child may extend a file layout; provided sections override defaults
         $Template = new Template(
            '@extends layout;@section title:T@section;@section content:CUSTOM@section;'
         );
         $Template->render();

         yield new Assertion(
            description: 'Provided section overrides the yield default block',
            fallback: "Template output does not match: \n`" . $Template->output . '`'
         )
            ->assert(
               actual: $Template->output,
               expected: "<header>T</header>\n<main>CUSTOM</main>\n"
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
