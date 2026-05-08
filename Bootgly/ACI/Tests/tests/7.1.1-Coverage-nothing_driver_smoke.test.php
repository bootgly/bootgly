<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Coverage;
use Bootgly\ACI\Tests\Coverage\Drivers\Nothing;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Coverage — Nothing driver completes a start/stop/report cycle',

   test: new Assertions(Case: function (): Generator {
      $Cov = new Coverage(new Nothing());
      $Cov->start();

      yield (new Assertion(description: 'driver flagged as running after start()'))
         ->expect($Cov->Driver->running)
         ->to->be(true)
         ->assert();

      $Cov->stop();

      yield (new Assertion(description: 'driver no longer running after stop()'))
         ->expect($Cov->Driver->running)
         ->to->be(false)
         ->assert();

      $text = $Cov->report('text');
      yield (new Assertion(description: 'text report renders without crashing'))
         ->expect(str_contains($text, 'Coverage report'))
         ->to->be(true)
         ->assert();

      $clover = $Cov->report('clover');
      yield (new Assertion(description: 'clover report is valid XML preamble'))
         ->expect(str_starts_with($clover, '<?xml'))
         ->to->be(true)
         ->assert();

      $html = $Cov->report('html');
      yield (new Assertion(description: 'html report has expected scaffold'))
         ->expect(str_contains($html, '<table>'))
         ->to->be(true)
         ->assert();
   })
);
