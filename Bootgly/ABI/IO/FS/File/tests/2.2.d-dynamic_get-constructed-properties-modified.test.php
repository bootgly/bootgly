<?php

use Bootgly\ABI\IO\FS\File;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;


return new Specification(
   Separator: new Separator(left: '__get - System'),
   description: '',
   test: function () {
      // @ Valid
      $File1 = new File(__DIR__ . '/1.1-construct-real_file.test.php');
      yield assert(
         assertion: is_int($File1->inode),
         description: 'File #1 - should have inode value!'
      );

      // @ Neutral
      $File2 = new File('');
      yield assert(
         assertion: $File2->inode === null,
         description: 'File #2 - empty path - inode should be null'
      );

      // @ Invalid
      $File3 = new File(__DIR__ . '/1.1.3-fake.test.php');
      yield assert(
         assertion: $File3->inode === null,
         description: 'File #3 - fake file - inode should be null'
      );
   }
);
