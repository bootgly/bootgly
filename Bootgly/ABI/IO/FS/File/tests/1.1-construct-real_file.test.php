<?php

use Bootgly\ABI\IO\FS\File;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: '',
   test: function () {
      // @ Valid
      // absolute real file to real file
      $File10 = new File(__DIR__ . '/@.php');
      yield assert(
         assertion: (string) $File10 === __DIR__ . '/@.php',
         description: 'Invalid Real File #1.0 (absolute real file): ' . $File10
      );

      // @ Neutral
      // ...

      // @ Invalid
      // ...
   }
);
