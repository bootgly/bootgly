<?php

use Bootgly\ABI\IO\FS\File;


return [
   // @ configure
   'describe' => '',
   'separator.line' => true,
   // @ simulate
   // ...
   // @ test
   'test' => function () {
      // @ Valid
      $File1 = new File;
      $File1->pathify(__DIR__ . '/1.1-construct-real_file.test.php');

      // @ get file contents
      $contents = $File1->contents;
      // @ set new file contents
      $File1->contents = PHP_EOL . '--TEST--';

      assert(
         assertion: $File1->lines === 2,
         description: 'Invalid Modified File lines count: ' . $File1->lines
      );

      // @ revert file contents
      $File1->contents = $contents;

      assert(
         assertion: $File1->lines === 31,
         description: 'Invalid Reverted File lines count: ' . $File1->lines
      );

      // @ Invalid
      $File2 = new File;
      $File2->pathify('');

      // @ get file contents
      $contents = $File2->contents;
      // @ set new file contents
      $File2->contents = PHP_EOL . '--TEST--';

      assert(
         assertion: $File2->lines === false,
         description: 'File #2 lines to invalid file should be false!'
      );

      // ---

      $File3 = new File;
      $File3->pathify(__DIR__ . '/1.1.3-fake.test.php');

      // @ get file contents
      $contents = $File3->contents;
      // @ set new file contents
      $File3->contents = PHP_EOL . '--TEST--';

      assert(
         assertion: $File3->lines === false,
         description: 'File #3 lines to invalid file should be false!'
      );

      return true;
   }
];
