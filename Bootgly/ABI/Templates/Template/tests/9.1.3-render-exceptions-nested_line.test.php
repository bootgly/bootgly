<?php

use Bootgly\ABI\Templates\Template;
use Bootgly\ABI\Templates\Template\Exceptions\TemplateException;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should map errors inside includes to the partial file and line',
   test: new Assertions(function () {
      // !
      $path = Template::$path;
      Template::$path = __DIR__ . '/templates/';

      try {
         // @ Invalid
         $Exception = null;
         try {
            new Template('@include partials/broken;')->render();
         }
         catch (TemplateException $Exception) {
         }

         yield new Assertion(
            description: 'Error maps to the partial file',
            fallback: 'Exception file: `' . ($Exception?->getFile() ?? '<none>') . '`'
         )
            ->assert(
               actual: str_ends_with(
                  (string) $Exception?->getFile(),
                  'partials/broken.template.php'
               ),
               expected: true
            );
         yield new Assertion(
            description: 'Error maps to the partial line',
            fallback: 'Exception line: `' . ($Exception?->getLine() ?? '<none>') . '`'
         )
            ->assert(
               actual: $Exception?->getLine(),
               expected: 2
            );
      }
      finally {
         // ! Restore
         Template::$path = $path;
      }
   })
);
