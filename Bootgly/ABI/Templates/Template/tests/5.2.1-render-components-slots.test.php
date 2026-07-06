<?php

use Bootgly\ABI\Templates\Template;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should render components with named and default slots',
   test: new Assertions(function () {
      // !
      $path = Template::$path;
      Template::$path = __DIR__ . '/templates/';

      try {
         // @ Valid
         $Template = new Template(
            '@component components/card:BODY@slot header:HEAD@slot;@component;'
         );
         $Template->render();

         yield new Assertion(
            description: 'Named slot and default slot fill the component',
            fallback: "Template output does not match: \n`" . $Template->output . '`'
         )
            ->assert(
               actual: $Template->output,
               expected: '<div>HEAD|BODY</div>'
            );

         // Components nest inside surrounding output
         $Template2 = new Template(
            'pre|@component components/card:B@slot header:H@slot;@component;|post'
         );
         $Template2->render();

         yield new Assertion(
            description: 'Component composes inline within surrounding output',
            fallback: "Template #2: output does not match: \n`" . $Template2->output . '`'
         )
            ->assert(
               actual: $Template2->output,
               expected: 'pre|<div>H|B</div>|post'
            );

         // Colons inside with-strings survive the payload parsing (opener is a single `:`)
         $Template3 = new Template(
            "@component components/card with ['x' => 'a:b']:B3@slot header:H3@slot;@component;"
         );
         $Template3->render();

         yield new Assertion(
            description: 'Colon inside a component with-string does not truncate the payload',
            fallback: "Template #3: output does not match: \n`" . $Template3->output . '`'
         )
            ->assert(
               actual: $Template3->output,
               expected: '<div>H3|B3</div>'
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
