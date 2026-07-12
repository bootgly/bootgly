<?php

use Bootgly\ACI\Process\State;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Process\State: a demoted non-root owner rewrites and tombstones its state without parent-directory writes',
   skip: posix_geteuid() === 0,
   test: function () {
      $directory = sys_get_temp_dir() . '/bootgly-state-demoted-' . getmypid() . '-' . bin2hex(random_bytes(4));
      mkdir($directory, 0700);

      $State = new State('StateDemotedCleanupTest', (string) getmypid());
      $State->pidFile = $directory . '/service.json';
      $State->pidLockFile = $directory . '/service.lock';
      $State->commandFile = $directory . '/service.command';
      $Property = new ReflectionClass(State::class)->getProperty('pidsDir');
      $Property->setValue($State, $directory . '/');

      try {
         $State->lock(LOCK_EX);
         file_put_contents($State->commandFile, "stale-command\n");
         $State->save(['master' => getmypid(), 'generation' => 1]);

         $directoryBefore = lstat($directory);
         $handedOff = $State->own(posix_geteuid(), posix_getegid());
         $directoryAfter = lstat($directory);

         yield assert(
            assertion: $handedOff
               && is_array($directoryBefore)
               && is_array($directoryAfter)
               && $directoryBefore['dev'] === $directoryAfter['dev']
               && $directoryBefore['ino'] === $directoryAfter['ino']
               && $directoryBefore['uid'] === $directoryAfter['uid']
               && $directoryBefore['gid'] === $directoryAfter['gid'],
            description: 'own() delegates only per-instance files and leaves the shared directory identity unchanged'
         );

         // ! Model the post-demotion deployment: the runtime UID owns the
         //   existing files but has no write permission on their parent.
         chmod($directory, 0555);
         $State->save(['master' => getmypid(), 'generation' => 2]);
         $pidBefore = lstat($State->pidFile);
         $commandBefore = lstat($State->commandFile);
         $lockBefore = lstat($State->pidLockFile);

         $State->clean();
         clearstatcache(true);

         $pidAfter = lstat($State->pidFile);
         $commandAfter = lstat($State->commandFile);
         $lockAfter = lstat($State->pidLockFile);
         yield assert(
            assertion: is_array($pidBefore)
               && is_array($pidAfter)
               && $pidBefore['ino'] === $pidAfter['ino']
               && filesize($State->pidFile) === 0
               && is_array($commandBefore)
               && is_array($commandAfter)
               && $commandBefore['ino'] === $commandAfter['ino']
               && filesize($State->commandFile) === 0
               && is_array($lockBefore)
               && is_array($lockAfter)
               && $lockBefore['ino'] === $lockAfter['ino'],
            description: 'clean() truncates PID and command under their stable inodes and preserves the lock inode'
         );

         yield assert(
            assertion: $State->check() === false && $State->read() === null,
            description: 'an empty PID tombstone is not reported as live process state'
         );

         // @ The same demoted identity can reuse all three protected inodes on
         //   reload/restart, still without creating or replacing pathnames.
         $Peer = new State('StateDemotedCleanupTest', (string) getmypid());
         $Peer->pidFile = $State->pidFile;
         $Peer->pidLockFile = $State->pidLockFile;
         $Peer->commandFile = $State->commandFile;
         $Property->setValue($Peer, $directory . '/');

         $relocked = $Peer->lock(LOCK_EX | LOCK_NB);
         $Peer->save(['master' => getmypid(), 'generation' => 3]);
         yield assert(
            assertion: $relocked
               && $Peer->check()
               && $Peer->read() === ['master' => getmypid(), 'generation' => 3],
            description: 'the demoted identity can relock and republish through the pre-created state inodes'
         );
         $Peer->clean();

         // @ A trusted storage administrator may encounter inaccessible files
         //   delegated to a dead runtime UID. Model that without privileges by
         //   removing this owner's write bits: directory authority can remove
         //   PID/command, but the stable lock pathname still survives.
         chmod($directory, 0700);
         $Peer->save(['master' => getmypid(), 'generation' => 4]);
         file_put_contents($Peer->commandFile, "stale-command\n");
         chmod($Peer->pidFile, 0400);
         chmod($Peer->commandFile, 0400);
         $adminLock = lstat($Peer->pidLockFile);
         $Peer->clean();
         $adminLockAfter = lstat($Peer->pidLockFile);
         yield assert(
            assertion: is_file($Peer->pidFile) === false
               && is_file($Peer->commandFile) === false
               && is_array($adminLock)
               && is_array($adminLockAfter)
               && $adminLock['ino'] === $adminLockAfter['ino'],
            description: 'the trusted storage admin removes inaccessible stale state without unlinking the lock inode'
         );
      }
      finally {
         chmod($directory, 0700);
         @unlink($State->pidFile);
         @unlink($State->pidLockFile);
         @unlink($State->commandFile);
         @rmdir($directory);
      }
   }
);
