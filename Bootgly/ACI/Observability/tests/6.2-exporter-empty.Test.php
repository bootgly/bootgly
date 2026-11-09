<?php

use Bootgly\ACI\Observability\Data\Snapshot;
use Bootgly\ACI\Observability\Exporters\JSON;
use Bootgly\ACI\Tests\Suite\Test;


return new Test(
   description: 'JSON exporter serializes empty metrics as an object, not an array',
   test: function () {
      $JSON = (new JSON)->export(new Snapshot());

      yield assert(
         assertion: str_contains($JSON, '"metrics":{}'),
         description: 'empty metrics serialize as {} (object, not [])'
      );
      yield assert(
         assertion: str_contains($JSON, '"timestamp":'),
         description: 'timestamp is always present'
      );
   }
);
