<?php

use Bootgly\ACI\Process\State;
use Bootgly\ACI\Tests\Suite\Test\Specification;

return new Specification(
   description: 'Process\State: atomic persistence and final-symlink refusal',
   test: function () {
      $State = new State('StateSafetyTest', (string) getmypid());
      $victim = sys_get_temp_dir() . '/bootgly-state-victim-' . getmypid();
      file_put_contents($victim, 'keep');

      try {
         symlink($victim, $State->pidFile);
         $refused = false;
         try {
            $State->save(['master' => getmypid()]);
         }
         catch (RuntimeException) {
            $refused = true;
         }
         yield assert(
            assertion: $refused && file_get_contents($victim) === 'keep',
            description: 'state persistence never follows a planted final PID-file symlink'
         );
         unlink($State->pidFile);

         $State->save(['master' => getmypid(), 'workers' => []]);
         yield assert(
            assertion: $State->read() === ['master' => getmypid(), 'workers' => []],
            description: 'an atomically committed regular state file round-trips under a shared read lock'
         );

         symlink($victim, $State->pidLockFile);
         yield assert(
            assertion: $State->lock(LOCK_EX | LOCK_NB) === false
               && file_get_contents($victim) === 'keep',
            description: 'the instance lock fails closed instead of following a planted symlink'
         );
         unlink($State->pidLockFile);

         yield assert(
            assertion: $State->lock(LOCK_EX | LOCK_NB) && $State->lock(LOCK_UN),
            description: 'a regular lock remains acquirable and releasable after the refusal'
         );

         $before = @lstat($State->pidLockFile);
         $Peer = new State('StateSafetyTest', (string) getmypid());
         $peerLocked = $Peer->lock(LOCK_EX | LOCK_NB);
         $after = @lstat($Peer->pidLockFile);
         yield assert(
            assertion: $peerLocked
               && is_array($before)
               && is_array($after)
               && ($before['dev'] ?? null) === ($after['dev'] ?? null)
               && ($before['ino'] ?? null) === ($after['ino'] ?? null),
            description: 'unlock preserves one stable lock inode for the next owner'
         );
         $Peer->lock(LOCK_UN);
      }
      finally {
         @unlink($State->pidFile);
         @unlink($State->pidLockFile);
         @unlink($State->commandFile);
         @unlink($victim);
      }
   }
);
