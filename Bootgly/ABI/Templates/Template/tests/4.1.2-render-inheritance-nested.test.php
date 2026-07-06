<?php

use Bootgly\ABI\Templates\Template;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should render inheritance: nested chain with child-wins sections',
   test: new Assertions(function () {
      // !
      $path = Template::$path;
      Template::$path = __DIR__ . '/templates/';

      try {
         // @ Valid
         // nested extends middle extends base; both define section `a`
         $Template = new Template(Template::resolve('nested'));
         $Template->render();

         yield new Assertion(
            description: 'Child section wins; intermediate fills the gaps',
            fallback: "Template output does not match: \n`" . $Template->output . '`'
         )
            ->assert(
               actual: $Template->output,
               // No trailing \n: PHP eats the newline right after the closing
               // tag compiled from the final `@yield b;`
               expected: 'A:child-a|B:middle-b'
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
