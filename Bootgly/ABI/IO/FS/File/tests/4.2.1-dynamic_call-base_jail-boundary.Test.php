<?php

use Bootgly\ABI\IO\FS\File;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'The `base` jail is authoritative in open(), create() and delete()',
   test: function () {
      // ! A jailed directory and a sibling directory the jail must never reach
      $root = sys_get_temp_dir() . '/bootgly-file-jail-' . getmypid();
      $jail = "$root/jail";
      $outside = "$root/outside";

      @mkdir($jail, 0775, true);
      @mkdir($outside, 0775, true);

      $secret = "$outside/secret.txt";
      file_put_contents($secret, "TOP-SECRET\n");

      // @ Denied — open() must not hand out a handle to a file outside the jail (IO-1)
      $File11 = new File($secret, base: $jail);
      $File11->open(File::READONLY_MODE);
      yield assert(
         assertion: is_resource($File11->handler) === false,
         description: 'Invalid File #1.1 handler: open() escaped the base jail!'
      );
      $File11->close();

      // @ Denied — delete() must not unlink a file outside the jail
      $victim = "$outside/victim.txt";
      file_put_contents($victim, "DO NOT DELETE\n");

      $File12 = new File($victim, base: $jail);
      $deleted = $File12->delete();
      yield assert(
         assertion: $deleted === false && is_file($victim) === true,
         description: 'Invalid File #1.2 delete(): unlinked a file outside the base jail!'
      );

      // @ Denied — create() must neither touch an outside path nor adopt it as ->file
      $target = "$outside/created.txt";

      $File13 = new File($target, base: $jail);
      $created = $File13->create();
      yield assert(
         assertion: $created === false && is_file($target) === false && $File13->file === '',
         description: 'Invalid File #1.3 create(): created a file outside the base jail!'
      );

      // @ Denied — a symlink planted inside the jail must not be followed out of it
      $escape = "$jail/escape.txt";
      $escaped = false;
      if (@symlink($secret, $escape) === true) {
         $File14 = new File($escape, base: $jail);
         $File14->open(File::READONLY_MODE);

         $escaped = is_resource($File14->handler);

         $File14->close();
      }
      yield assert(
         assertion: $escaped === false,
         description: 'Invalid File #1.4 handler: open() followed a symlink out of the base jail!'
      );

      // @ Allowed — an existing file inside the jail still opens
      $inside = "$jail/inside.txt";
      file_put_contents($inside, "INSIDE\n");

      $File21 = new File($inside, base: $jail);
      $File21->open(File::READONLY_MODE);
      yield assert(
         assertion: is_resource($File21->handler),
         description: 'Invalid File #2.1 handler: open() denied a file inside the base jail!'
      );
      $File21->close();

      // @ Allowed — a file that does not exist yet is judged by its parent directory
      $fresh = "$jail/sub/fresh.txt";

      $File22 = new File($fresh, base: $jail);
      $created = $File22->create();
      yield assert(
         assertion: $created === true && is_file($fresh) === true,
         description: 'Invalid File #2.2 create(): denied a new file inside the base jail!'
      );

      // @ Allowed — delete() inside the jail
      $File23 = new File($fresh, base: $jail);
      $deleted = $File23->delete();
      yield assert(
         assertion: $deleted === true && is_file($fresh) === false,
         description: 'Invalid File #2.3 delete(): denied a file inside the base jail!'
      );

      // @ Unconstrained — a File with no base keeps reaching anywhere
      $File31 = new File($secret);
      $File31->open(File::READONLY_MODE);
      yield assert(
         assertion: is_resource($File31->handler),
         description: 'Invalid File #3.1 handler: open() constrained a File with no base!'
      );
      $File31->close();

      // ! Teardown
      @unlink($escape);
      @unlink($secret);
      @unlink($victim);
      @unlink($inside);
      @rmdir("$jail/sub");
      @rmdir($jail);
      @rmdir($outside);
      @rmdir($root);
   }
);
