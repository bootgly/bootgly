<?php

use Bootgly\ACI\Process\State;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Process\State: the kernel lock table backs identity when /proc/<pid>/fd is hidden',
   test: function () {
      // ! A process that gains privileges at exec is marked non-dumpable, so
      //   the kernel hands its `fd/` and `fdinfo/` to root — the documented
      //   `setcap cap_net_bind_service=+ep` on the PHP binary puts every
      //   Bootgly server there. `authenticate()` then falls back to
      //   `/proc/locks`, so that table must keep naming the flock holder in
      //   the exact shape the fallback parses.
      $instance = (string) getmypid();
      $Holder = new State('StateLockTableTest', $instance);

      try {
         $locked = $Holder->lock(LOCK_EX | LOCK_NB);

         $inode = $locked ? @stat($Holder->pidLockFile)['ino'] ?? 0 : 0;
         $table = (string) @file_get_contents('/proc/locks');
         $pattern = '/^\d+:\s+FLOCK\s+ADVISORY\s+WRITE\s+' . getmypid()
            . '\s+[0-9a-f]+:[0-9a-f]+:' . $inode . '\s+0\s+EOF\s*$/mi';

         yield assert(
            assertion: $locked && $inode > 0 && preg_match($pattern, $table) === 1,
            description: 'the kernel lock table names the flock holder against the lock inode'
         );
         yield assert(
            assertion: $Holder->authenticate(getmypid()),
            description: 'the flock owner authenticates from the kernel lock evidence'
         );
         // ? PID 1 never holds this inode, so no evidence source may accept it.
         yield assert(
            assertion: $Holder->authenticate(1) === false,
            description: 'a process that holds no lock on the inode never authenticates'
         );

         // ---

         $Holder->lock(LOCK_UN);
         $released = (string) @file_get_contents('/proc/locks');

         yield assert(
            assertion: preg_match($pattern, $released) !== 1
               && $Holder->authenticate(getmypid()) === false,
            description: 'releasing the lock withdraws the evidence and the identity with it'
         );
      }
      finally {
         $Holder->lock(LOCK_UN);
         @unlink($Holder->pidFile);
         @unlink($Holder->pidLockFile);
         @unlink($Holder->commandFile);
      }
   }
);
