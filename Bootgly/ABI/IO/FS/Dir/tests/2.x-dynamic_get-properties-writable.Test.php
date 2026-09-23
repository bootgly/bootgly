<?php

use Bootgly\ABI\IO\FS\Dir;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;


return new Test(
   description: '',
   test: function () {
      // ! Owned scratch directories: writability is decided by their mode and
      //   the running identity, never by a host path assumption — root
      //   overrides DAC, so a system directory such as `/sbin` IS writable
      //   for root. The oracle is a real write attempt.
      $directory = Temporaries::reserve('dir-writable');
      $locked = "{$directory}/locked";
      mkdir($locked, 0o700);
      chmod($locked, 0o555);

      try {
         // @ Valid
         // writable
         $Dir1 = new Dir($directory);
         yield assert(
            assertion: $Dir1->writable === true,
            description: 'An owned directory is writable!'
         );

         // read-only mode: writable exactly when a real write succeeds
         $probe = "{$locked}/probe";
         $written = @file_put_contents($probe, 'x') !== false;
         @unlink($probe);

         $Dir2 = new Dir($locked);
         yield assert(
            assertion: $Dir2->writable === $written,
            description: 'A 0555 directory reports the writability a real write shows!'
         );
      }
      finally {
         @chmod($locked, 0o700);
         @rmdir($locked);
         @rmdir($directory);
      }
   }
);
