<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Coverage;
use Bootgly\ACI\Tests\Coverage\Driver;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Coverage — text report diff handles missing source files gracefully',

   test: new Assertions(Case: function (): Generator {
      $file = tempnam(sys_get_temp_dir(), 'bootgly-coverage-missing-');
      if ($file === false) {
         throw new RuntimeException('Could not create coverage diff fixture.');
      }
      unlink($file);

      $Driver = new class ($file) extends Driver {
         public function __construct (private string $file) {}

         public function collect (): array
         {
            return [$this->file => [1 => 1]];
         }
      };

      $Cov = new Coverage($Driver);
      $Cov->diff = true;
      $Cov->start();
      $Cov->stop();

      $text = $Cov->report('text');

      yield (new Assertion(description: 'missing file is reported instead of crashing'))
         ->expect(str_contains($text, '# Source unavailable:'))
         ->to->be(true)
         ->assert();
   })
);
