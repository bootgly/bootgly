<?php

use Bootgly\ABI\IO\FS\File;
use Bootgly\ABI\IO\FS\File\MIME;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Suite\Test\Separator;


return new Test(
   Separator: new Separator(left: '__get - Content'),
   description: '',
   test: function () {
      // @ Valid
      $File1 = new File(__DIR__ . '/1.1-construct-real_file.Test.php');
      $MIME1 = $File1->MIME;
      yield assert(
         assertion: $MIME1->type === 'text/x-php',
         description: 'File #1 - MIME type: ' . $MIME1->type
      );

      // @ Neutral
      $File2 = new File('');
      $MIME2 = $File2->MIME;
      yield assert(
         assertion: $MIME2 === null,
         description: 'File #2 - MIME should be null'
      );

      // @ Invalid
      $File3 = new File(__DIR__ . '/1.1.3-fake.Test.php');
      $MIME3 = $File3->MIME;
      yield assert(
         assertion: $MIME3 === null,
         description: 'File #3 (fake) - MIME should be null'
      );

      // @ Invalid — the public constructor takes an arbitrary filename, and
      //   `mime_content_type()` warns on what it cannot open, throws on an empty
      //   name, and returns a type with no `/` to split (IO-3)
      $probes = [
         'non-existent path' => __DIR__ . '/1.1.3-fake.Test.php',
         'directory'         => __DIR__,
         'empty filename'    => '',
      ];
      foreach ($probes as $probe => $filename) {
         $thrown = null;
         $MIME4 = null;
         try {
            $MIME4 = new MIME($filename);
         }
         catch (Throwable $Throwable) {
            $thrown = $Throwable::class . ': ' . $Throwable->getMessage();
         }

         yield assert(
            assertion: $thrown === null
               && $MIME4 instanceof MIME
               && $MIME4->type === ''
               && $MIME4->format === ''
               && $MIME4->subtype === '',
            description: "File #4 ($probe) - MIME parts should be empty, thrown: "
               . var_export($thrown, true)
         );
      }
   }
);
