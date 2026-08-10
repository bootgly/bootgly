<?php

use Bootgly\ABI\IO\FS\File;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: '',
   test: function () {
      // @ Valid
      $File1 = new File(__DIR__ . '/1.1-construct-real_file.Test.php');
      yield assert(
         assertion: $File1->size === 493,
         description: 'File #1 size: ' . $File1->size
      );

      // @ Neutral
      $File2 = new File('');
      yield assert(
         assertion: $File2->size === null,
         description: 'File #2 should have size === null!'
      );

      // @ Invalid
      $File3 = new File(__DIR__ . '/1.1.3-fake.Test.php');
      yield assert(
         assertion: $File3->size === null,
         description: 'File #3 should have size === null!'
      );
   }
);
