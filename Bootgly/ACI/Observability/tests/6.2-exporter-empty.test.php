<?php

use Bootgly\ACI\Observability\Data\Snapshot;
use Bootgly\ACI\Observability\Exporters\JSON;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'JSON exporter serializes empty metrics as an object, not an array',
   test: function () {
      $json = (new JSON)->export(new Snapshot());

      yield assert(
         assertion: str_contains($json, '"metrics":{}'),
         description: 'empty metrics serialize as {} (object, not [])'
      );
      yield assert(
         assertion: str_contains($json, '"timestamp":'),
         description: 'timestamp is always present'
      );
   }
);
