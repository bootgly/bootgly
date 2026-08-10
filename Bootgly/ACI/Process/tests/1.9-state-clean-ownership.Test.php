<?php

use Bootgly\ACI\Process\State;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Process\State: only the local exclusive-lock owner can clean process state',
   skip: function_exists('pcntl_fork') === false,
   test: function () {
      $instance = (string) getmypid();
      $Holder = new State('StateCleanOwnershipTest', $instance);
      $Contender = new State('StateCleanOwnershipTest', $instance);
      $PID = -1;
      $status = 0;

      try {
         $locked = $Holder->lock(LOCK_EX | LOCK_NB);
         $Holder->save(['master' => getmypid(), 'workers' => []]);

         $PID = $locked ? pcntl_fork() : -1;
         if ($PID === 0) {
            $cleanRejected = $Holder->clean() === false;
            $unlockRejected = $Holder->lock(LOCK_UN) === false;

            exit($cleanRejected && $unlockRejected ? 0 : 1);
         }

         $waited = $PID > 0 ? pcntl_waitpid($PID, $status) : -1;
         if ($waited === $PID) {
            $PID = -1;
         }
         $childRejected = $waited > 0
            && pcntl_wifexited($status)
            && pcntl_wexitstatus($status) === 0;
         $stillPublished = $Holder->check();
         $stillLocked = $Contender->lock(LOCK_EX | LOCK_NB) === false;

         yield assert(
            assertion: $locked && $childRejected && $stillPublished && $stillLocked,
            description: 'a fork-inherited descriptor cannot clean or unlock the master state'
         );

         $cleaned = $Holder->clean();
         $reacquired = $Contender->lock(LOCK_EX | LOCK_NB);
         yield assert(
            assertion: $cleaned
               && $Holder->check() === false
               && $reacquired,
            description: 'the acquiring PID cleans and releases the exact instance lock'
         );
      }
      finally {
         if ($PID > 0) {
            $reaped = pcntl_waitpid($PID, $status, WNOHANG);
            if ($reaped === 0) {
               posix_kill($PID, SIGTERM);
               pcntl_waitpid($PID, $status);
            }
         }
         $Holder->clean();
         $Contender->clean();
         $Holder->lock(LOCK_UN);
         $Contender->lock(LOCK_UN);
         @unlink($Holder->pidFile);
         @unlink($Holder->pidLockFile);
         @unlink($Holder->commandFile);
      }
   }
);
