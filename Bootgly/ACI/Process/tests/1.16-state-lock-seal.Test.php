<?php


use Bootgly\ACI\Process\State;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Process\State: a reused lock inode is sealed to 0600, so the instance it guards stays authenticable',
   test: function () {
      // ! `open()` applies its mask only when it CREATES the inode. A lock
      //   restored from a backup (cp/tar/rsync without mode preservation),
      //   pre-created by a deploy, or left by another UID arrives 0644 — and
      //   `authenticate()` demands exactly 0600. Without the seal the server
      //   starts, binds and serves on a lock no control command can verify.
      $id = 'StateSealTest';
      $instance = '9997';
      $file = BOOTGLY_STORAGE_DIR . "pids/$id.$instance.lock";

      @unlink($file);
      touch($file);
      chmod($file, 0644);
      clearstatcache(true, $file);

      // ? Precondition — the planted inode really is world-readable
      yield assert(
         assertion: (fileperms($file) & 0777) === 0644,
         description: 'The planted lock inode starts at 0644'
      );

      // @
      $State = new State($id, $instance);

      yield assert(
         assertion: $State->lock(LOCK_EX | LOCK_NB) === true,
         description: 'lock() acquires a pre-existing 0644 inode'
      );

      clearstatcache(true, $file);
      yield assert(
         assertion: (fileperms($file) & 0777) === 0600,
         description: 'lock() seals the reused inode to 0600'
      );

      yield assert(
         assertion: $State->authenticate(posix_getpid()) === true,
         description: 'The sealed lock authenticates its holder'
      );

      // ! Negative control — the 0600 demand is the security property the
      //   seal exists to satisfy, not an accident of this run
      chmod($file, 0644);
      clearstatcache(true, $file);

      yield assert(
         assertion: $State->authenticate(posix_getpid()) === false,
         description: 'A lock loosened back to 0644 is refused by authenticate()'
      );

      // ! Cleanup
      chmod($file, 0600);
      $State->lock(LOCK_UN);
      @unlink($file);
   }
);
