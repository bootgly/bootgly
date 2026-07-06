<?php

use Bootgly\ABI\Templates\Template;
use Bootgly\ABI\Templates\Template\Exceptions\TemplateException;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should throw TemplateException on inheritance cycles',
   test: new Assertions(function () {
      // !
      $path = Template::$path;
      Template::$path = __DIR__ . '/templates/';

      try {
         // @ Invalid
         // cycle-a extends cycle-b extends cycle-a
         $Exception = null;
         try {
            new Template(Template::resolve('cycle-a'))->render();
         }
         catch (TemplateException $Exception) {
         }

         yield new Assertion(
            description: 'Inheritance cycle is detected',
            fallback: 'Message: `' . ($Exception?->getMessage() ?? '<none>') . '`'
         )
            ->assert(
               actual: str_contains((string) $Exception?->getMessage(), 'cycle'),
               expected: true
            );
         yield new Assertion(
            description: 'Cycle maps to the @extends template file',
            fallback: 'Exception file: `' . ($Exception?->getFile() ?? '<none>') . '`'
         )
            ->assert(
               actual: str_ends_with((string) $Exception?->getFile(), 'cycle-a.template.php'),
               expected: true
            );
         yield new Assertion(
            description: 'Cycle maps to the @extends line',
            fallback: 'Exception line: `' . ($Exception?->getLine() ?? '<none>') . '`'
         )
            ->assert(
               actual: $Exception?->getLine(),
               expected: 1
            );
      }
      finally {
         // ! Restore
         Template::$path = $path;
      }
   })
);
