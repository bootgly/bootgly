<?php

use Bootgly\ACI\Schedule\State;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'State: fetch() defaults to 0; update() round-trips and persists across instances',
   test: function () {
      @unlink(BOOTGLY_WORKING_DIR . '/workdata/schedule/state.json');

      $State = new State();

      yield assert(
         assertion: $State->fetch('unknown') === 0,
         description: 'Unknown id returns 0'
      );

      $State->update('job', 1234567890);

      yield assert(
         assertion: $State->fetch('job') === 1234567890,
         description: 'update() then fetch() round-trips'
      );

      // @ A fresh instance reads the persisted file
      $Reloaded = new State();
      yield assert(
         assertion: $Reloaded->fetch('job') === 1234567890,
         description: 'State persists across instances'
      );
   }
);
