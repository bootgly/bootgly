<?php

use const LOCK_EX;
use const LOCK_NB;
use const LOCK_UN;

use Bootgly\ACI\Process\State;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Process\State: non-blocking lock — holder wins, same-qualifier contender fails, other qualifiers stay independent',
   test: function () {
      // ! flock is bound to the open file description, so two State objects
      //   over the same lock file contend even inside a single process.
      $Holder = new State('StateLockTest', '9999');

      yield assert(
         assertion: $Holder->lock(LOCK_EX | LOCK_NB) === true,
         description: 'First holder acquires the non-blocking lock'
      );

      // @ Same qualifier: a second instance must be rejected
      $Contender = new State('StateLockTest', '9999');

      yield assert(
         assertion: $Contender->lock(LOCK_EX | LOCK_NB) === false,
         description: 'Second instance on the same qualifier fails the non-blocking lock'
      );

      // @ Different qualifier (another port): independent lock
      $Other = new State('StateLockTest', '9998');

      yield assert(
         assertion: $Other->lock(LOCK_EX | LOCK_NB) === true,
         description: 'Another qualifier (port) locks independently'
      );

      // @ Releasing frees the qualifier for the next instance
      $Holder->lock(LOCK_UN);
      $Retry = new State('StateLockTest', '9999');

      yield assert(
         assertion: $Retry->lock(LOCK_EX | LOCK_NB) === true,
         description: 'After LOCK_UN the qualifier is acquirable again'
      );

      // ! Cleanup
      $Retry->lock(LOCK_UN);
      $Other->lock(LOCK_UN);
   }
);
