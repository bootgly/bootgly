<?php

use Bootgly\ABI\IO\FS\File;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Suite\Test\Separator;


return new Test(
   Separator: new Separator(line: true),
   description: '',
   test: function () {
      // @ Valid
      $File10 = new File(__DIR__ . '/1.1-construct-real_file.Test.php');
      yield assert(
         assertion: $File10->basename === '1.1-construct-real_file.Test.php',
         description: 'File #1.0 basename: ' . $File10->basename
      );

      // @ Neutral
      $File20 = new File('');
      yield assert(
         assertion: $File20->basename === false,
         description: 'File #2.0 basename should be false!'
      );

      // @ Invalid
      $File30 = new File(__DIR__ . '/1.1.3-fake.Test.php');
      yield assert(
         assertion: $File30->basename === '1.1.3-fake.Test.php',
         description: 'File #3.0 (fake) basename: ' . $File30->basename
      );
   }
);
