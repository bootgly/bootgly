<?php

use Bootgly\ACI\Process\State;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Process\State: root delegates one instance without granting its runtime UID the shared directory',
   skip: posix_geteuid() !== 0 || function_exists('pcntl_fork') === false,
   test: function () {
      $directory = sys_get_temp_dir() . '/bootgly-state-root-' . getmypid() . '-' . bin2hex(random_bytes(4));
      mkdir($directory, 0755);
      chmod($directory, 0755);

      $State = new State('StateRootHandoffTest', (string) getmypid());
      $State->pidFile = $directory . '/service.json';
      $State->pidLockFile = $directory . '/service.lock';
      $State->commandFile = $directory . '/service.command';
      $Property = new ReflectionClass(State::class)->getProperty('pidsDir');
      $Property->setValue($State, $directory . '/');

      $administrator = lstat(rtrim(BOOTGLY_STORAGE_DIR, '/'));
      // ? Linux reserves 65534 for the unprivileged overflow/nobody identity;
      //   select a distinct numeric identity even if storage itself uses it.
      $UID = ($administrator['uid'] ?? null) === 65534 ? 65533 : 65534;
      $GID = ($administrator['gid'] ?? null) === 65534 ? 65533 : 65534;

      try {
         $State->lock(LOCK_EX);
         $State->lock(LOCK_UN);
         file_put_contents($State->commandFile, "stale-command\n");
         $State->save(['master' => getmypid()]);

         // ! Model the vulnerable ownership/mode left by the previous release.
         chown($directory, $UID);
         chgrp($directory, $GID);
         chmod($directory, 0777);
         $directoryBefore = lstat($directory);
         $handedOff = $State->own($UID, $GID);
         $directoryAfter = lstat($directory);
         $pid = lstat($State->pidFile);
         $lock = lstat($State->pidLockFile);
         $command = lstat($State->commandFile);

         yield assert(
            assertion: $handedOff
               && is_array($directoryBefore)
               && is_array($directoryAfter)
               && is_array($administrator)
               && $directoryBefore['ino'] === $directoryAfter['ino']
               && $directoryBefore['uid'] === $UID
               && $directoryAfter['uid'] === $administrator['uid']
               && $directoryAfter['gid'] === $administrator['gid']
               && ((int) $directoryAfter['mode'] & 0777) === 0755
               && is_array($pid) && $pid['uid'] === $UID && $pid['gid'] === $GID
               && is_array($lock) && $lock['uid'] === $UID && $lock['gid'] === $GID
               && is_array($command) && $command['uid'] === $UID && $command['gid'] === $GID,
            description: 'root reclaims a legacy runtime-owned directory and delegates only per-instance files'
         );

         $Child = pcntl_fork();
         if ($Child === 0) {
            try {
               if (posix_setgid($GID) === false || posix_setuid($UID) === false) {
                  exit(2);
               }
               $State->save(['master' => getmypid(), 'demoted' => true]);
               if ($State->check() === false) {
                  exit(3);
               }
               $State->clean();
               exit($State->check() ? 4 : 0);
            }
            catch (Throwable) {
               exit(5);
            }
         }

         pcntl_waitpid($Child, $status);
         clearstatcache(true);
         $directoryAfterChild = lstat($directory);
         yield assert(
            assertion: $Child > 0
               && pcntl_wexitstatus($status) === 0
               && is_array($directoryAfterChild)
               && $directoryAfterChild['uid'] === $administrator['uid']
               && $directoryAfterChild['gid'] === $administrator['gid']
               && filesize($State->pidFile) === 0
               && filesize($State->commandFile) === 0,
            description: 'the actually demoted UID republishes and cleans its files without directory authority'
         );
      }
      finally {
         @unlink($State->pidFile);
         @unlink($State->pidLockFile);
         @unlink($State->commandFile);
         @rmdir($directory);
      }
   }
);
