<?php

use Bootgly\ABI\Templates\Template;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'It should render components whose body overrides the default slot',
   test: new Assertions(function () {
      // !
      $path = Template::$path;
      Template::$path = __DIR__ . '/templates/';

      try {
         // @ Valid
         // An explicit `slot` used to short-circuit seal()'s `??=` over
         // ob_get_clean(), leaving the component buffer open: it then swallowed
         // the page and was returned in place of it (TPL-3)
         $Template = new Template(
            'PRE@component components/card:BODY@slot slot:EXPLICIT@slot;@component;POST'
         );
         $Template->render();

         yield new Assertion(
            description: 'Overriding the default slot keeps the surrounding page output',
            fallback: "Template output does not match: \n`" . $Template->output . '`'
         )
            ->assert(
               actual: $Template->output,
               expected: 'PRE<div>|EXPLICIT</div>POST'
            );

         // The @section spelling of the same override
         $Template2 = new Template(
            'PRE@component components/card:BODY@section slot:EXPLICIT@section;@component;POST'
         );
         $Template2->render();

         yield new Assertion(
            description: '@section slot: overrides the body the same way',
            fallback: "Template #2: output does not match: \n`" . $Template2->output . '`'
         )
            ->assert(
               actual: $Template2->output,
               expected: 'PRE<div>|EXPLICIT</div>POST'
            );

         // Overriding the default slot alongside a named one
         $Template3 = new Template(
            'PRE@component components/card:BODY'
            . '@slot header:HEAD@slot;@slot slot:EXPLICIT@slot;@component;POST'
         );
         $Template3->render();

         yield new Assertion(
            description: 'A named slot and an overridden default slot compose together',
            fallback: "Template #3: output does not match: \n`" . $Template3->output . '`'
         )
            ->assert(
               actual: $Template3->output,
               expected: 'PRE<div>HEAD|EXPLICIT</div>POST'
            );

         // @ Neutral
         // The frame's buffer is closed by seal(), not left for render()'s drain
         $level = ob_get_level();

         $Template4 = new Template(
            '@component components/card:BODY@slot slot:EXPLICIT@slot;@component;'
         );
         $Template4->render();

         yield new Assertion(
            description: 'The render leaves no output buffer open',
            fallback: 'Output buffer level does not match: ' . ob_get_level()
         )
            ->assert(
               actual: ob_get_level(),
               expected: $level
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
