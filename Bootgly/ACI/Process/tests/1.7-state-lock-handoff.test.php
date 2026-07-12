<?php

use Bootgly\ACI\Process\State;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Process\State: an inherited open-file-description preserves the instance lock across owner handoff',
   test: function () {
      $instance = (string) getmypid();
      $Holder = new State('StateLockHandoffTest', $instance);
      $locked = $Holder->lock(LOCK_EX | LOCK_NB);
      $Descriptor = $Holder->export();
      $exported = is_resource($Descriptor);

      $marker = sys_get_temp_dir() . '/bootgly-state-lock-handoff-' . getmypid();
      $ready = $marker . '.ready';
      $release = $marker . '.release';
      @unlink($ready);
      @unlink($release);

      $PID = $exported ? pcntl_fork() : -1;
      if ($PID === 0) {
         // A transferred descriptor must never be accepted for a different
         // qualified pathname, even though it is a valid regular file.
         $Wrong = new State('StateLockHandoffTest', $instance . '-other');
         if ($Wrong->adopt($Descriptor)) {
            exit(2);
         }

         $Receiver = new State('StateLockHandoffTest', $instance);
         if ($Receiver->adopt($Descriptor) === false) {
            exit(3);
         }
         if (file_put_contents($ready, 'ready') === false) {
            exit(4);
         }

         $deadline = microtime(true) + 5.0;
         while (is_file($release) === false && microtime(true) < $deadline) {
            usleep(1000);
         }
         if (is_file($release) === false) {
            exit(5);
         }

         // Close, do not explicitly unlock: another duplicate could still be
         // the serving owner of this open-file-description.
         $Receiver->detach();
         exit(0);
      }

      $adopted = false;
      $continuous = false;
      $reacquired = false;
      $Contender = null;
      if ($PID > 0) {
         $deadline = microtime(true) + 5.0;
         while (is_file($ready) === false && microtime(true) < $deadline) {
            usleep(1000);
         }

         if (is_file($ready)) {
            // This models the old master closing its duplicate before exec.
            $Holder->detach();
            $Contender = new State('StateLockHandoffTest', $instance);
            $continuous = $Contender->lock(LOCK_EX | LOCK_NB) === false;
         }

         file_put_contents($release, 'release');
         $waited = pcntl_waitpid($PID, $status);
         $adopted = $waited === $PID
            && pcntl_wifexited($status)
            && pcntl_wexitstatus($status) === 0;

         if ($Contender instanceof State) {
            $reacquired = $Contender->lock(LOCK_EX | LOCK_NB);
         }
      }

      yield assert(
         assertion: $locked && $exported,
         description: 'only an acquired descriptor on the exact lock inode is exportable'
      );
      yield assert(
         assertion: $adopted && $continuous,
         description: 'the receiver validates the qualified inode and keeps it locked after the sender detaches'
      );
      yield assert(
         assertion: $reacquired,
         description: 'the stable lock becomes acquirable only after the final handed-off duplicate closes'
      );

      if ($Contender instanceof State) {
         $Contender->clean();
      }
      else {
         $Holder->clean();
      }
      @unlink($Holder->pidFile);
      @unlink($Holder->commandFile);
      @unlink($Holder->pidLockFile);
      @unlink($ready);
      @unlink($release);
   }
);
