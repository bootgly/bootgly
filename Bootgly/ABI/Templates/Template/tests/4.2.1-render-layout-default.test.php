<?php

use Bootgly\ABI\Templates\Template;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should wrap a top-level template in the configured default layout',
   test: new Assertions(function () {
      // !
      $path = Template::$path;
      $layout = Template::$layout;
      Template::$path = __DIR__ . '/templates/';

      try {
         // @ Valid
         // A template with no @extends is wrapped: its loose output becomes the
         //   layout's `content` section.
         Template::$layout = 'layout';
         $Template = new Template('Hello World');
         $Template->render();

         yield new Assertion(
            description: 'A template without @extends is wrapped in the default layout',
            fallback: "Template #A output does not match: \n`" . $Template->output . '`'
         )
            ->assert(
               actual: $Template->output,
               expected: "<header></header>\n<main>Hello World</main>\n"
            );

         // An explicit @extends always wins over the default layout.
         Template::$layout = 'base';
         $Template = new Template(Template::resolve('child'));
         $Template->render();

         yield new Assertion(
            description: 'An explicit @extends overrides the default layout',
            fallback: "Template #B output does not match: \n`" . $Template->output . '`'
         )
            ->assert(
               actual: $Template->output,
               expected: "<header>Child Title</header>\n<main>fallback content</main>\n"
            );

         // An empty default layout renders the template bare.
         Template::$layout = '';
         $Template = new Template('Just this');
         $Template->render();

         yield new Assertion(
            description: 'An empty default layout renders the template unwrapped',
            fallback: "Template #C output does not match: \n`" . $Template->output . '`'
         )
            ->assert(
               actual: $Template->output,
               expected: 'Just this'
            );

         // An explicit @section content wins over the injected loose output.
         Template::$layout = 'layout';
         $Template = new Template('@section content:SECTION@section;loose');
         $Template->render();

         yield new Assertion(
            description: 'An explicit @section content wins over the injected loose output',
            fallback: "Template #D output does not match: \n`" . $Template->output . '`'
         )
            ->assert(
               actual: $Template->output,
               expected: "<header></header>\n<main>SECTION</main>\n"
            );

         // @ Invalid
         // ...
      }
      finally {
         // ! Restore
         Template::$path = $path;
         Template::$layout = $layout;
      }
   })
);
