<?php

use Bootgly\ABI\IO\FS\File;
use Bootgly\ABI\Templates\Template;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'It should render Template file',
   test: function () {
      // @ Valid
      $Template = new Template(
         new File(__DIR__ . '/testing.template.php')
      );
      $Template->render([
         'a'     => 'easy',
         'b'     => true,
         #'c' => false
      ]);
      yield assert(
         assertion: $Template->output === <<<'OUTPUT'
         Bootgly Template is easy!
         Bootgly Template is 1!
         Bootgly Template is !
         Bootgly Template is $d!
         OUTPUT,
         description: "Template output does not match: \n`" . $Template->output . '`'
      );

      // @ Neutral
      // ...

      // @ Invalid
      // ...
   }
);
