<?php

use Bootgly\ACI\Queues;
use Bootgly\ACI\Queues\Worker;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Worker::tick returns false on an empty queue',
   test: function () {
      $path = sys_get_temp_dir() . '/bootgly-queue-' . uniqid('', true);

      $Queues = new Queues(['path' => $path]);
      $Queue = $Queues->fetch('default');
      $Worker = new Worker($Queue, $Queues->Config);

      yield assert(
         assertion: $Worker->tick() === false,
         description: 'an empty queue yields no work'
      );
   }
);
