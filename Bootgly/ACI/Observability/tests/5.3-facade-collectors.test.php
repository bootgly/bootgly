<?php

use Bootgly\ACI\Observability;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Facade auto-registers default health collectors and folds them into the snapshot',
   test: function () {
      // # Default: Process + Runtime collectors active
      $O = new Observability();
      $Snapshot = $O->gather();

      yield assert(
         assertion: isset($Snapshot->metrics['process_memory_bytes'])
            && isset($Snapshot->metrics['runtime_included_files']),
         description: 'gather() includes process + runtime health metrics by default'
      );

      // # Opt-out: bare registry without collectors
      $Bare = new Observability(collectors: false);
      $bareSnapshot = $Bare->gather();

      yield assert(
         assertion: isset($bareSnapshot->metrics['process_memory_bytes']) === false,
         description: 'collectors:false yields a registry with no health metrics'
      );
   }
);
