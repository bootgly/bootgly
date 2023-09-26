<?php

use Bootgly\ABI\IO\FS\File;
use Bootgly\ABI\Templates\Template;

return [
   // @ configure
   'describe' => 'It should render Template file',
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      // @ Valid
      $Template = new Template(
         new File(__DIR__ . '/testing.template.php')
      );
      $Template->render([
         'a'     => 'easy',
         'b'     => true,
         #'c' => false
      ]);
      assert(
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

      return true;
   }
];
