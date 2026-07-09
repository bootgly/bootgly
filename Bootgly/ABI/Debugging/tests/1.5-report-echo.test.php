<?php

use Bootgly\ABI\Debugging\Data\Throwables;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'report() echoes exactly what render() returns for the default target',
   test: function () {
      $Throwable = new Exception('echo equivalence probe');

      $rendered = Throwables::render($Throwable);

      ob_start();
      Throwables::report($Throwable);
      $echoed = ob_get_clean();

      yield assert(
         assertion: $echoed === $rendered,
         description: 'echoed output matches the rendered string'
      );
   }
);
