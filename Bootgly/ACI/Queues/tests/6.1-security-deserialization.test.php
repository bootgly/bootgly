<?php

use Bootgly\ACI\Queues;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'a tampered non-Job payload in the store is rejected (no object-injection on unserialize)',
   test: function () {
      $path = sys_get_temp_dir() . '/bootgly-qsec-' . uniqid('', true);
      $Queue = new Queues(['path' => $path])->fetch('default');

      // @ Plant a hostile serialized object (not a Job) straight into the ready dir
      $dir = $path . '/default/ready';
      mkdir($dir, 0775, true);
      file_put_contents($dir . '/00000000000-evil.job', serialize(new ArrayObject(['x' => 1])));

      // ? unserialize() is restricted to Job via allowed_classes — the foreign object
      //   is never reconstructed, so reserve() drops it instead of returning a live gadget
      yield assert(
         assertion: $Queue->reserve() === null,
         description: 'a non-Job payload is never deserialized into a live object'
      );
   }
);
