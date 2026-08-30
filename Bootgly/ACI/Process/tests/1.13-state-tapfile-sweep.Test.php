<?php

use Bootgly\ACI\Process\State;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'Process\State: the tap socket joins the instance file family and sweep() reclaims it',
   test: function () {
      $pidsDir = BOOTGLY_STORAGE_DIR . 'pids/';

      // # qualify() names the tap socket beside the json/lock/command triple
      $State = new State('StateTapfileTest', '9001');
      yield assert(
         assertion: $State->tapFile === "{$pidsDir}StateTapfileTest.9001.logs.sock",
         description: 'tapFile is the fourth qualified member of the instance family'
      );

      $State->qualify(null);
      yield assert(
         assertion: $State->tapFile === "{$pidsDir}StateTapfileTest.logs.sock",
         description: 'qualify(null) unqualifies the tap pathname too'
      );

      // # sweep(): an abandoned instance's tap socket is reclaimed with its siblings
      $id = 'StateTapfileOrphan.9002';
      file_put_contents("$pidsDir$id.lock", '');
      file_put_contents("$pidsDir$id.json", '{"master":1}');
      file_put_contents("$pidsDir$id.logs.sock", ''); // a plain-file stand-in for the inode
      foreach (['lock', 'json', 'logs.sock'] as $extension) {
         touch("$pidsDir$id.$extension", time() - 600);
      }

      (new State('StateTapfileTest', '9001'))->sweep();

      yield assert(
         assertion: is_file("$pidsDir$id.lock") === false
            && is_file("$pidsDir$id.json") === false
            && file_exists("$pidsDir$id.logs.sock") === false,
         description: 'sweep() reclaims the tap socket beside the lock/json of a dead instance'
      );

      // # sweep(): a keyed tap orphan (lock already gone) is reclaimed alone
      $orphan = 'StateTapfileLone.9003';
      file_put_contents("$pidsDir$orphan.logs.sock", '');
      touch("$pidsDir$orphan.logs.sock", time() - 600);

      (new State('StateTapfileTest', '9001'))->sweep();

      yield assert(
         assertion: file_exists("$pidsDir$orphan.logs.sock") === false,
         description: 'the orphan pass reclaims a tap socket whose lock is already gone'
      );

      // ! Cleanup (nothing should remain, but never leave residue on failure)
      foreach ([$id, $orphan] as $stale) {
         @unlink("$pidsDir$stale.lock");
         @unlink("$pidsDir$stale.json");
         @unlink("$pidsDir$stale.command");
         @unlink("$pidsDir$stale.logs.sock");
      }
   }
);
