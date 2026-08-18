<?php

use Bootgly\ACI\Schedule\Lock;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Lock: release() keeps the lock inode, so a released lock still excludes a third arrival',
   test: function () {
      // ! A per-process id — the lock inode now outlives the run by design.
      $id = 'lock_inode_' . getmypid();

      $A = new Lock($id);
      $B = new Lock($id);

      yield assert(
         assertion: $A->acquire() === true,
         description: 'the first holder acquires'
      );

      $inode = fileinode($A->file);

      // ! B loses this round — and `acquire()` caches its handle, so B keeps the
      //   ORIGINAL inode open. Retrying is exactly what the next tick does.
      yield assert(
         assertion: $B->acquire() === false,
         description: 'a competitor is excluded while the lock is held'
      );

      $A->release();
      clearstatcache();

      // # Exclusion is keyed to the inode, not to the path. B re-acquires the
      //   inode it kept open, so a third arrival opening the same path must be
      //   turned away. When release() unlinks, C instead creates a NEW inode
      //   and locks that — and both are told they own the lock, which is the
      //   overlap this class exists to prevent.
      yield assert(
         assertion: $B->acquire() === true,
         description: 'the waiting competitor takes the released lock'
      );

      $C = new Lock($id);
      yield assert(
         assertion: $C->acquire() === false,
         description: 'a third arrival never holds the lock at the same time as the second'
      );

      yield assert(
         assertion: is_file($A->file) === true,
         description: 'release() leaves the lock file in place'
      );
      yield assert(
         assertion: fileinode($A->file) === $inode,
         description: 'the lock inode keeps its identity across a release'
      );

      // @ Cleanup — the inode is intentionally never removed by the class.
      $C->release();
      $B->release();
      @unlink($A->file);
   }
);
