<?php

use Bootgly\ABI\IO\FS\File;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;


return new Specification(
   Separator: new Separator(line: true),
   description: '',
   test: function () {
      // @ Valid
      $File1 = new File(__DIR__ . '/1.1-construct-real_file.test.php');
      yield assert(
         assertion: $File1->exists,
         description: 'File #1 should exist!'
      );

      // @ Neutral
      $File2 = new File('');
      yield assert(
         assertion: $File2->exists === false,
         description: 'File #2 should not exist!'
      );

      // @ Invalid
      $File3 = new File(__DIR__ . '/1.1.3-fake.test.php');
      yield assert(
         assertion: $File3->exists === false,
         description: 'File #3 should not exist!'
      );
   }
);
