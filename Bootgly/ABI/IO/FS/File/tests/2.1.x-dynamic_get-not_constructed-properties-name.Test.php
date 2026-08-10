<?php

use Bootgly\ABI\IO\FS\File;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: '',
   test: function () {
      // @ Valid
      $File1 = new File(__DIR__ . '/1.1-construct-real_file.Test.php');
      yield assert(
         assertion: $File1->name === '1.1-construct-real_file.Test',
         description: 'File #1 name: ' . $File1->name
      );

      $File2 = new File(__DIR__ . '/1.1.3-fake.Test.php');
      yield assert(
         assertion: $File2->name === '1.1.3-fake.Test',
         description: 'File #2 (fake) name: ' . $File2->name
      );

      // @ Neutral
      $File3 = new File('');
      yield assert(
         assertion: $File3->name === false,
         description: 'File #3 name should be false!'
      );

      // @ Invalid
      // ...
   }
);
