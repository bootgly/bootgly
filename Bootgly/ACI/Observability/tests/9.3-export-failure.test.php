<?php

use Bootgly\ACI\Observability;
use Bootgly\ACI\Observability\Exporters\JSON;
use Bootgly\ACI\Observability\Metrics\Gauge;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'JSON export fails to "" on non-finite values; dump() refuses to overwrite a good file',
   test: function () {
      $O = new Observability(collectors: false);
      $Gauge = new Gauge(name: 'broken');
      $Gauge->set(NAN);
      $O->Metrics->push($Gauge);

      yield assert(
         assertion: $O->export(new JSON) === '',
         description: 'export returns "" when a value is non-finite (never a misleading {})'
      );

      // # dump() must keep a previous good snapshot rather than overwrite it with garbage
      $dir = sys_get_temp_dir() . '/bootgly-obs-m2-' . uniqid();
      mkdir($dir, 0775, true);
      $path = "$dir/worker.json";
      file_put_contents($path, '{"good":true}');

      $ok = $O->dump(new JSON, $path);

      yield assert(
         assertion: $ok === false,
         description: 'dump() returns false on encode failure'
      );
      yield assert(
         assertion: file_get_contents($path) === '{"good":true}',
         description: 'the previous good snapshot is preserved'
      );

      @unlink($path);
      @rmdir($dir);
   }
);
