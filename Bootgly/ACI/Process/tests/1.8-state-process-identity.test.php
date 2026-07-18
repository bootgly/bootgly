<?php

use Bootgly\ACI\Process\State;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Process\State: PID identity is bound to the exact qualified kernel-held flock',
   test: function () {
      $instance = (string) getmypid();
      $Holder = new State('StateProcessIdentityTest', $instance);
      $Other = new State('StateProcessIdentityTest', $instance . '-other');
      $marker = sys_get_temp_dir() . '/bootgly-state-identity-setsid-' . getmypid();
      @unlink($marker);
      $PID = -1;
      $SessionPID = -1;

      try {
         $locked = $Holder->lock(LOCK_EX | LOCK_NB);
         $master = $locked && $Holder->authenticate(getmypid());
         $nonpositive = $Holder->authenticate(0) === false;

         $otherCreated = $Other->lock(LOCK_EX | LOCK_NB)
            && $Other->lock(LOCK_UN);
         $wrongInstance = $otherCreated
            && $Other->authenticate(getmypid()) === false;

         $PID = $locked ? pcntl_fork() : -1;
         if ($PID === 0) {
            usleep(5000000);
            $Holder->detach();
            exit(0);
         }

         $worker = false;
         $workerMaster = false;
         $wrongParent = false;
         if ($PID > 0) {
            usleep(50000);
            $worker = $Holder->authenticate($PID, getmypid());
            $workerMaster = $Holder->authenticate($PID);
            $wrongParent = $Holder->authenticate($PID, getmypid() + 1);
            posix_kill($PID, SIGTERM);
            pcntl_waitpid($PID, $status);
            $PID = -1;
         }

         $SessionPID = $locked ? pcntl_fork() : -1;
         if ($SessionPID === 0) {
            $detached = posix_setsid() !== -1;
            file_put_contents($marker, $detached ? 'ready' : 'failed');
            usleep(5000000);
            $Holder->detach();
            exit(0);
         }
         $sessionRejected = false;
         if ($SessionPID > 0) {
            $deadline = microtime(true) + 2.0;
            while (is_file($marker) === false && microtime(true) < $deadline) {
               usleep(1000);
            }
            $sessionRejected = @file_get_contents($marker) === 'ready'
               && $Holder->authenticate($SessionPID) === false;
            posix_kill($SessionPID, SIGTERM);
            pcntl_waitpid($SessionPID, $sessionStatus);
            $SessionPID = -1;
         }

         chmod($Holder->pidLockFile, 0644);
         $unsafeMode = $Holder->authenticate(getmypid());
         chmod($Holder->pidLockFile, 0600);

         yield assert(
            assertion: $master && $nonpositive,
            description: 'the flock owner authenticates while invalid PIDs fail closed'
         );
         yield assert(
            assertion: $wrongInstance,
            description: 'a lock from another qualified instance cannot authenticate this PID'
         );
         yield assert(
            assertion: $worker,
            description: 'an inherited descriptor authenticates a direct worker of the flock owner'
         );
         yield assert(
            assertion: $workerMaster === false,
            description: 'an inherited worker descriptor cannot impersonate the master role'
         );
         yield assert(
            assertion: $wrongParent === false,
            description: 'an inherited descriptor cannot authenticate against a different parent'
         );
         yield assert(
            assertion: $sessionRejected,
            description: 'an inherited holder cannot impersonate the master by becoming a session leader'
         );
         yield assert(
            assertion: $unsafeMode === false,
            description: 'a group/world-accessible lock inode is never an identity capability'
         );

         $Holder->lock(LOCK_UN);
         yield assert(
            assertion: $Holder->authenticate(getmypid()) === false,
            description: 'an unlocked stale inode cannot authenticate a process'
         );
      }
      finally {
         foreach ([$PID, $SessionPID] as $childPID) {
            if ($childPID <= 0) {
               continue;
            }
            $reaped = pcntl_waitpid($childPID, $status, WNOHANG);
            if ($reaped === 0) {
               posix_kill($childPID, SIGTERM);
               pcntl_waitpid($childPID, $status);
            }
         }
         @chmod($Holder->pidLockFile, 0600);
         $Holder->lock(LOCK_UN);
         $Other->lock(LOCK_UN);
         @unlink($Holder->pidFile);
         @unlink($Holder->pidLockFile);
         @unlink($Holder->commandFile);
         @unlink($Other->pidFile);
         @unlink($Other->pidLockFile);
         @unlink($Other->commandFile);
         @unlink($marker);
      }
   }
);
