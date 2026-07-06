<?php

use Bootgly\ABI\IO\FS\File;
use Bootgly\ABI\Templates\Template;
use Bootgly\ABI\Templates\Template\Exceptions\TemplateException;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should map engine-raised exceptions to the directive line',
   test: new Assertions(function () {
      // !
      $path = Template::$path;
      $file = sys_get_temp_dir() . '/bootgly-' . uniqid() . '.template.php';

      try {
         // @ Invalid
         // Missing @include target maps to the @include line
         Template::$path = __DIR__ . '/templates/';
         file_put_contents($file, "ok\n@include missing;\n");

         $Exception1 = null;
         try {
            new Template(new File($file))->render();
         }
         catch (TemplateException $Exception1) {
         }

         yield new Assertion(
            description: 'Missing include maps to the template file',
            fallback: 'Exception file: `' . ($Exception1?->getFile() ?? '<none>') . '`'
         )
            ->assert(
               actual: $Exception1?->getFile(),
               expected: $file
            );
         yield new Assertion(
            description: 'Missing include maps to the @include line',
            fallback: 'Exception line: `' . ($Exception1?->getLine() ?? '<none>') . '`'
         )
            ->assert(
               actual: $Exception1?->getLine(),
               expected: 2
            );

         // Unconfigured Template::$path maps to the @include line
         Template::$path = '';
         file_put_contents($file, '@include partials/alert;');
         touch($file, time() + 2);

         $Exception2 = null;
         try {
            new Template(new File($file))->render();
         }
         catch (TemplateException $Exception2) {
         }

         yield new Assertion(
            description: 'Unconfigured path maps to the template file',
            fallback: 'Exception file: `' . ($Exception2?->getFile() ?? '<none>') . '`'
         )
            ->assert(
               actual: $Exception2?->getFile(),
               expected: $file
            );
         yield new Assertion(
            description: 'Unconfigured path maps to the @include line',
            fallback: 'Exception line: `' . ($Exception2?->getLine() ?? '<none>') . '`'
         )
            ->assert(
               actual: $Exception2?->getLine(),
               expected: 1
            );

         // Missing @extends parent maps to the @extends line (via origin)
         Template::$path = __DIR__ . '/templates/';
         file_put_contents($file, "@extends nope;\n");
         touch($file, time() + 4);

         $Exception3 = null;
         try {
            new Template(new File($file))->render();
         }
         catch (TemplateException $Exception3) {
         }

         yield new Assertion(
            description: 'Missing extends parent maps to the child template file',
            fallback: 'Exception file: `' . ($Exception3?->getFile() ?? '<none>') . '`'
         )
            ->assert(
               actual: $Exception3?->getFile(),
               expected: $file
            );
         yield new Assertion(
            description: 'Missing extends parent maps to the @extends line',
            fallback: 'Exception line: `' . ($Exception3?->getLine() ?? '<none>') . '`'
         )
            ->assert(
               actual: $Exception3?->getLine(),
               expected: 1
            );
      }
      finally {
         // ! Restore
         Template::$path = $path;
         @unlink($file);
      }
   })
);
