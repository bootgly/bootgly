<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Coverage;
use Bootgly\ACI\Tests\Coverage\Driver;
use Bootgly\ACI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Coverage — scope filter keeps framework sources while excluding test scripts',

   test: new Assertions(Case: function (): Generator {
      $source = '/repo/Bootgly/ACI/Tests/Mock.php';
      $script = '/repo/Bootgly/ACI/Tests/tests/4.1.1-Mock-stub_and_verify.test.php';
      $outside = '/repo/Bootgly/ACI/Logs/Logger.php';

      $Driver = new class ($source, $script, $outside) extends Driver {
         public function __construct (
            private string $source,
            private string $script,
            private string $outside,
         ) {}

         public function collect (): array
         {
            return [
               $this->source  => [10 => 1],
               $this->script  => [20 => 1],
               $this->outside => [30 => 1],
            ];
         }
      };

      $Coverage = new Coverage($Driver);
      $Coverage->includes = ['Bootgly/ACI/Tests'];
      $Coverage->start();
      $Coverage->stop();

      yield (new Assertion(description: 'uppercase Tests framework source remains reportable'))
         ->expect(isset($Coverage->data[$source]))
         ->to->be(true)
         ->assert();

      yield (new Assertion(description: 'lowercase tests scripts remain excluded'))
         ->expect(isset($Coverage->data[$script]))
         ->to->be(false)
         ->assert();

      yield (new Assertion(description: 'files outside the include scope remain excluded'))
         ->expect(isset($Coverage->data[$outside]))
         ->to->be(false)
         ->assert();
   })
);
